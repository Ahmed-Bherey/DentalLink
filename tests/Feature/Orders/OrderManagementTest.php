<?php

namespace Tests\Feature\Orders;

use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Models\Financial\OrderItem;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baseline of the CURRENT order behaviour.
 */
class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_doctor_can_place_an_order(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $product->id, 'quantity' => 2]],
                'notes' => 'please deliver quickly',
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', ['doctor_id' => $doctor->id, 'status' => 'pending']);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 2]);
    }

    /** @test */
    public function a_supplier_cannot_place_an_order(): void
    {
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('orders', 0);
    }

    /** @test */
    public function order_creation_validates_its_input(): void
    {
        $doctor = User::factory()->doctor()->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products');

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => [['id' => 99999, 'quantity' => 0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.id', 'products.0.quantity']);
    }

    /** @test */
    public function a_doctor_sees_only_their_own_orders(): void
    {
        $doctor = User::factory()->doctor()->create();
        $other = User::factory()->doctor()->create();

        Order::factory()->count(2)->placedBy($doctor)->create();
        Order::factory()->count(3)->placedBy($other)->create();

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/order/index-type')
            ->assertSuccessful();

        $this->assertSame(2, $response->json('meta.total'));
    }

    /** @test */
    public function a_supplier_sees_only_orders_containing_their_products(): void
    {
        $supplier = User::factory()->supplier()->create();
        $other = User::factory()->supplier()->create();

        $mine = Order::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $mine->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
        ]);

        $theirs = Order::factory()->create();
        OrderItem::factory()->create([
            'order_id' => $theirs->id,
            'product_id' => Product::factory()->ownedBy($other)->create()->id,
        ]);

        $response = $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/order/index-type')
            ->assertSuccessful();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    /** @test */
    public function the_owning_supplier_can_advance_the_order_status(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $order = Order::factory()->placedBy($doctor)->create(['status' => 'pending']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'preparing'])
            ->assertSuccessful();

        $this->assertSame('preparing', $order->fresh()->status);
    }

    /** @test */
    public function order_status_is_restricted_to_the_known_values(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $order = Order::factory()->placedBy($doctor)->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'not-a-status'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // ------------------------------------------------- documented defects

    /**
     * BUSINESS LOGIC DEFECT CONFIRMED — DEFERRED TO ORDER/INVENTORY STAGE.
     *
     * OrderService::store() deletes the doctor's ENTIRE cart after creating the
     * order, even when the order covered only part of it.
     *
     * @test
     */
    public function placing_an_order_currently_clears_the_entire_cart(): void
    {
        $doctor = User::factory()->doctor()->create();

        $ordered = Product::factory()->create();
        $untouched = Product::factory()->create();

        Cart::factory()->ownedBy($doctor)->create(['product_id' => $ordered->id]);
        Cart::factory()->ownedBy($doctor)->create(['product_id' => $untouched->id]);

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $ordered->id, 'quantity' => 1]],
            ])
            ->assertSuccessful();

        $this->assertSame(
            0,
            Cart::where('doctor_id', $doctor->id)->count(),
            'The unordered cart line is destroyed too. Known defect.'
        );
    }

    /**
     * BUSINESS LOGIC DEFECT CONFIRMED — DEFERRED TO ORDER/INVENTORY STAGE.
     *
     * Nothing checks available stock at order time.
     *
     * @test
     */
    public function an_order_can_currently_exceed_available_stock(): void
    {
        $doctor = User::factory()->doctor()->create();
        $product = Product::factory()->create(['quantity' => 1]);

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $product->id, 'quantity' => 500]],
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 500]);
        $this->assertEquals(1, $product->fresh()->quantity, 'Stock is untouched at order time.');
    }

    /**
     * BUSINESS LOGIC DEFECT CONFIRMED — DEFERRED TO ORDER/INVENTORY STAGE.
     *
     * OrderController::update() guards with
     *     if ($order->status != 'pending' || $order->status != 'preparing')
     * which is always true, so the endpoint always returns 403 and
     * OrderService::update() is unreachable.
     *
     * @test
     */
    public function the_order_update_endpoint_is_currently_unreachable(): void
    {
        $doctor = User::factory()->doctor()->create();
        $order = Order::factory()->placedBy($doctor)->create(['status' => 'pending']);

        // Even the owner, on a 'pending' order, is refused.
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/order/update/{$order->id}", ['notes' => 'updated'])
            ->assertStatus(403);

        $this->assertNotSame('updated', $order->fresh()->notes);
    }

    /**
     * BUSINESS LOGIC DEFECT CONFIRMED — DEFERRED TO ORDER/INVENTORY STAGE.
     *
     * There is no state machine: any status may transition to any other, so a
     * delivered order can be moved back to pending.
     *
     * @test
     */
    public function order_status_transitions_are_currently_unrestricted(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $order = Order::factory()->placedBy($doctor)->create(['status' => 'delivered']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
        ]);

        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'pending'])
            ->assertSuccessful();

        $this->assertSame('pending', $order->fresh()->status, 'delivered -> pending is currently allowed.');
    }
}
