<?php

namespace Tests\Feature\Security;

use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Models\Financial\OrderItem;
use App\Models\Financial\Package;
use App\Models\Financial\Payment;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDOR / BOLA regression suite.
 *
 * Each test proves that the owner of a record can act on it and that a
 * non-owner cannot. These exercise the interim ownership guards added in
 * Stage 1. When the real policy layer lands, the implementation changes but
 * these assertions must not — they are the security contract.
 *
 * Safety: tests/TestCase::guardTestDatabase() aborts the run unless the
 * configured database is a dedicated test database.
 */
class OwnershipBoundaryTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- products

    /** @test */
    public function a_supplier_cannot_update_another_suppliers_product(): void
    {
        $owner = User::factory()->supplier()->create();
        $attacker = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($owner)->pricedAt(100)->create();

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/product/update/{$product->id}", ['price' => 1])
            ->assertStatus(404);

        $this->assertEquals(100, $product->fresh()->price, 'Price must be unchanged by a non-owner.');
    }

    /** @test */
    public function a_supplier_can_update_their_own_product(): void
    {
        $owner = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($owner)->pricedAt(100)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/product/update/{$product->id}", ['price' => 150])
            ->assertSuccessful();

        $this->assertEquals(150, $product->fresh()->price);
    }

    /** @test */
    public function a_supplier_cannot_delete_another_suppliers_product(): void
    {
        $owner = User::factory()->supplier()->create();
        $attacker = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($owner)->create();

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/product/delete/{$product->id}")
            ->assertStatus(404);

        $this->assertNotNull($product->fresh(), 'Product must survive a non-owner delete.');
    }

    /** @test */
    public function a_supplier_can_delete_their_own_product(): void
    {
        $owner = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/product/delete/{$product->id}")
            ->assertSuccessful();

        $this->assertNull($product->fresh());
    }

    /**
     * STATUS CHANGED IN STAGE 4: 403 -> 404.
     *
     * Stage 1 returned 403 here while single-product delete returned 404, which
     * was inconsistent AND leakier: a 403 confirmed "that id exists but is not
     * yours", whereas the single-delete path revealed nothing. ProductPolicy now
     * denies as NOT FOUND uniformly, so bulk and single behave the same and
     * neither can be used to probe for other suppliers' product ids.
     *
     * This is a tightening, not a relaxation — the batch is still fully refused
     * and both products still survive, which is what the test actually guards.
     *
     * @test
     */
    public function bulk_delete_refuses_a_batch_containing_another_suppliers_product(): void
    {
        $owner = User::factory()->supplier()->create();
        $attacker = User::factory()->supplier()->create();
        $mine = Product::factory()->ownedBy($attacker)->create();
        $theirs = Product::factory()->ownedBy($owner)->create();

        $this->actingAs($attacker, 'sanctum')
            ->postJson('/api/product/multi-delete', ['ids' => [$mine->id, $theirs->id]])
            ->assertStatus(404);

        $this->assertNotNull($theirs->fresh(), 'Another supplier product must survive.');
        $this->assertNotNull($mine->fresh(), 'The whole batch must be refused, not partially applied.');
    }

    /** @test */
    public function a_supplier_cannot_read_another_suppliers_product(): void
    {
        $owner = User::factory()->supplier()->create();
        $attacker = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($owner)->create();

        $this->actingAs($attacker, 'sanctum')
            ->getJson("/api/product/show/{$product->id}")
            ->assertStatus(404);
    }

    // ----------------------------------------------------------------- cart

    /** @test */
    public function a_doctor_cannot_modify_another_doctors_cart_line(): void
    {
        $owner = User::factory()->doctor()->create();
        $attacker = User::factory()->doctor()->create();
        $cart = Cart::factory()->ownedBy($owner)->create(['quantity' => 1]);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/cart/update/{$cart->id}", ['quantity' => 99])
            ->assertStatus(404);

        $this->assertEquals(1, $cart->fresh()->quantity);
    }

    /** @test */
    public function a_doctor_cannot_delete_another_doctors_cart_line(): void
    {
        $owner = User::factory()->doctor()->create();
        $attacker = User::factory()->doctor()->create();
        $cart = Cart::factory()->ownedBy($owner)->create();

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/cart/delete/{$cart->id}")
            ->assertStatus(404);

        $this->assertNotNull($cart->fresh());
    }

    /** @test */
    public function a_doctor_can_modify_their_own_cart_line(): void
    {
        $owner = User::factory()->doctor()->create();
        $cart = Cart::factory()->ownedBy($owner)->create(['quantity' => 1]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/cart/update/{$cart->id}", ['quantity' => 5])
            ->assertSuccessful();

        $this->assertEquals(5, $cart->fresh()->quantity);
    }

    // ------------------------------------------------------------- packages

    /** @test */
    public function a_supplier_cannot_delete_another_suppliers_package(): void
    {
        $owner = User::factory()->supplier()->create();
        $attacker = User::factory()->supplier()->create();
        $package = Package::factory()->ownedBy($owner)->create();

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/package/delete/{$package->id}")
            ->assertStatus(403);

        $this->assertNotNull($package->fresh());
    }

    /** @test */
    public function a_supplier_cannot_toggle_another_suppliers_package(): void
    {
        $owner = User::factory()->supplier()->create();
        $attacker = User::factory()->supplier()->create();
        $package = Package::factory()->ownedBy($owner)->create(['active' => true]);

        $this->actingAs($attacker, 'sanctum')
            ->getJson("/api/package/toggle-status/{$package->id}")
            ->assertStatus(403);

        $this->assertTrue((bool) $package->fresh()->active);
    }

    /** @test */
    public function a_supplier_can_toggle_their_own_package(): void
    {
        $owner = User::factory()->supplier()->create();
        $package = Package::factory()->ownedBy($owner)->create(['active' => true]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/package/toggle-status/{$package->id}")
            ->assertSuccessful();

        $this->assertFalse((bool) $package->fresh()->active);
    }

    // -------------------------------------------------------------- payments

    /** @test */
    public function a_third_party_cannot_confirm_someone_elses_payment(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $attacker = User::factory()->doctor()->create();

        $payment = Payment::factory()->between($doctor, $supplier)->withStatus('pending')->create();

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    /** @test */
    public function the_supplier_party_cannot_confirm_their_own_payment(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $payment = Payment::factory()->between($doctor, $supplier)->withStatus('pending')->create();

        // Confirmation is the doctor decision; the supplier must not self-approve.
        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    /** @test */
    public function a_third_party_cannot_request_deletion_of_someone_elses_payment(): void
    {
        $payment = Payment::factory()->withStatus('confirmed')->create();
        $attacker = User::factory()->supplier()->create();

        $this->actingAs($attacker, 'sanctum')
            ->getJson("/api/payment/delete/{$payment->id}")
            ->assertStatus(403);

        $this->assertSame('confirmed', $payment->fresh()->status);
    }

    /** @test */
    public function a_third_party_cannot_amend_someone_elses_payment(): void
    {
        $payment = Payment::factory()->of(100)->create();
        $attacker = User::factory()->supplier()->create();

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/payment/update/{$payment->id}", [
                'paid' => 999,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(403);

        $this->assertEquals(100, $payment->fresh()->amount);
        $this->assertNull($payment->fresh()->requested_amount);
    }

    // ---------------------------------------------------------------- orders

    /** @test */
    public function a_supplier_who_owns_no_line_in_an_order_cannot_change_its_status(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $attacker = User::factory()->supplier()->create();

        $order = Order::factory()->placedBy($doctor)->create(['status' => 'pending']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
        ]);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function a_doctor_cannot_mark_their_own_order_delivered(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $order = Order::factory()->placedBy($doctor)->create(['status' => 'pending']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
        ]);

        // Self-crediting inventory and fabricating a receivable was the original exploit.
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function a_doctor_cannot_flag_another_doctors_order_for_deletion(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $attacker = User::factory()->doctor()->create();

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/order/delete/{$order->id}")
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function a_doctor_can_flag_their_own_order_for_deletion(): void
    {
        $doctor = User::factory()->doctor()->create();
        $order = Order::factory()->placedBy($doctor)->create(['status' => 'pending']);

        $this->actingAs($doctor, 'sanctum')
            ->deleteJson("/api/order/delete/{$order->id}")
            ->assertSuccessful();

        $this->assertSame('delete_pending', $order->fresh()->status);
    }

    /** @test */
    public function a_supplier_who_does_not_own_the_product_cannot_approve_a_return(): void
    {
        $supplier = User::factory()->supplier()->create();
        $attacker = User::factory()->supplier()->create();

        $order = Order::factory()->delivered()->create();
        $item = OrderItem::factory()->pendingReturn(2)->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
            'quantity' => 5,
        ]);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertSame('delete_pending', $item->fresh()->status);
    }

    /** @test */
    public function a_doctor_cannot_request_a_return_on_another_doctors_order_item(): void
    {
        $order = Order::factory()->delivered()->create();
        $item = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 5]);

        $attacker = User::factory()->doctor()->create();

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 1])
            ->assertStatus(403);

        $this->assertSame('confirmed', $item->fresh()->status);
    }

    // ---------------------------------------------------------------- report

    /**
     * STATUS CHANGED IN STAGE 4: 422 -> 404.
     *
     * The 422 was never intentional: Stage 1 scoped the lookup to doctors, the
     * resulting ModelNotFoundException fell into the controller's generic
     * `catch (Exception)`, and that returned 422 "something went wrong".
     *
     * UserPolicy::viewCounterparty now denies as NOT FOUND, which is both the
     * correct status and consistent with every other non-enumerable resource.
     * The denial itself is unchanged — a supplier still cannot read another
     * supplier's record.
     *
     * @test
     */
    public function a_user_cannot_read_an_arbitrary_user_record_through_the_doctor_report(): void
    {
        $targetSupplier = User::factory()->supplier()->create();
        $caller = User::factory()->supplier()->create();

        // The endpoint must only ever resolve doctors, never other suppliers.
        $this->actingAs($caller, 'sanctum')
            ->getJson("/api/report/doctor/{$targetSupplier->id}/details")
            ->assertStatus(404);
    }

    /** @test */
    public function a_supplier_can_read_a_doctor_through_the_doctor_report(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $this->actingAs($supplier, 'sanctum')
            ->getJson("/api/report/doctor/{$doctor->id}/details")
            ->assertSuccessful();
    }
}
