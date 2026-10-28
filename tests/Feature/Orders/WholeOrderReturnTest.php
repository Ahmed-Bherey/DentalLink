<?php

namespace Tests\Feature\Orders;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Financial\OrderItemRefund;
use App\Models\Store\Product;
use App\Models\User;
use App\Services\Financial\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for whole-order returns and quantity edits (H-2, H-4).
 *
 * The whole-order path used to reverse the charge at the product's CURRENT
 * price with float arithmetic, record no refund (so the doctor's paid amount
 * never moved), and hard-delete the order and its lines.
 */
class WholeOrderReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = User::factory()->doctor()->create();
        $this->supplier = User::factory()->supplier()->create();
    }

    private function offer(float $price, int $stock = 100): Product
    {
        return Product::factory()
            ->ownedBy($this->supplier)
            ->of(ProductMaster::factory()->create())
            ->pricedAt($price)
            ->create(['quantity' => $stock]);
    }

    private function deliveredOrder(array $lines): Order
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => $lines])
            ->assertSuccessful();

        $order = Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->firstOrFail();

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertSuccessful();

        return $order->fresh();
    }

    private function requestWholeOrderReturn(Order $order)
    {
        return $this->actingAs($this->doctor, 'sanctum')
            ->deleteJson("/api/order/delete/{$order->id}");
    }

    private function decideWholeOrderReturn(Order $order, string $status)
    {
        return $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => $status]);
    }

    private function ledger(): ?OrderExpense
    {
        return OrderExpense::where('doctor_id', $this->doctor->id)
            ->where('supplier_id', $this->supplier->id)
            ->first();
    }

    private function assertInvariantsHold(): void
    {
        $v = app(LedgerService::class)->invariantViolations($this->ledger()->refresh());

        $this->assertSame([], $v, 'Ledger invariants broken: '.implode('; ', $v));
    }

    // ===================================================== H-2

    /** @test */
    public function a_whole_order_return_refunds_at_the_historical_price()
    {
        $offer = $this->offer(10);
        $order = $this->deliveredOrder([['id' => $offer->id, 'quantity' => 5]]);

        $this->assertEquals(50, $this->ledger()->total);

        // The supplier reprices AFTER delivery.
        $offer->update(['price' => 25]);

        $this->requestWholeOrderReturn($order)->assertSuccessful();
        $this->decideWholeOrderReturn($order, 'confirmed')->assertSuccessful();

        $refund = OrderItemRefund::firstOrFail();

        $this->assertEquals(10, $refund->unit_price, 'Settled at the price charged, not 25.');
        $this->assertEquals(50, $refund->amount, '5 x 10, not 5 x 25.');
        $this->assertEquals(0, $this->ledger()->total);
        $this->assertInvariantsHold();
    }

    /** @test */
    public function a_whole_order_return_preserves_the_order_and_its_lines()
    {
        $order = $this->deliveredOrder([['id' => $this->offer(10)->id, 'quantity' => 3]]);

        $this->requestWholeOrderReturn($order)->assertSuccessful();
        $this->decideWholeOrderReturn($order, 'confirmed')->assertSuccessful();

        // The records survive — they used to be hard-deleted.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'returned']);
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'quantity' => 3,
            'returned_quantity' => 3,
            'line_total' => '30.00',
        ]);
    }

    /** @test */
    public function a_whole_order_return_reduces_effective_paid_as_well_as_the_charge()
    {
        $order = $this->deliveredOrder([['id' => $this->offer(100)->id, 'quantity' => 10]]);

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => 1000,
                'date' => now()->toDateString(),
            ])->assertSuccessful();

        $this->requestWholeOrderReturn($order)->assertSuccessful();
        $this->decideWholeOrderReturn($order, 'confirmed')->assertSuccessful();

        $ledger = $this->ledger();

        $this->assertEquals(0, $ledger->total);
        $this->assertEquals(0, $ledger->paid, 'The money went back to the doctor.');
        $this->assertEquals(0, $ledger->remaining);
        $this->assertInvariantsHold();
    }

    /**
     * The double-refund guard: units already returned line-by-line must not be
     * refunded again by a whole-order return.
     *
     * @test
     */
    public function a_whole_order_return_does_not_refund_quantities_already_returned()
    {
        $order = $this->deliveredOrder([['id' => $this->offer(10)->id, 'quantity' => 5]]);
        $item = $order->orderItems()->first();

        // Return 2 of 5 per line first.
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 2])
            ->assertSuccessful();
        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => 'confirmed'])
            ->assertSuccessful();

        $this->assertEquals(30, $this->ledger()->total, '50 - 20.');

        // Now return the whole order: only the remaining 3 may be refunded.
        $this->requestWholeOrderReturn($order->fresh())->assertSuccessful();
        $this->decideWholeOrderReturn($order->fresh(), 'confirmed')->assertSuccessful();

        $this->assertEquals(50, OrderItemRefund::sum('amount'), 'Total refunded is 50, never more.');
        $this->assertSame(5, (int) $item->fresh()->returned_quantity);
        $this->assertEquals(0, $this->ledger()->total);
        $this->assertInvariantsHold();
    }

    /** @test */
    public function a_whole_order_return_returns_the_stock()
    {
        $offer = $this->offer(10, 100);
        $order = $this->deliveredOrder([['id' => $offer->id, 'quantity' => 5]]);

        $this->assertSame(95, (int) $offer->fresh()->quantity);

        $this->requestWholeOrderReturn($order)->assertSuccessful();
        $this->decideWholeOrderReturn($order, 'confirmed')->assertSuccessful();

        $this->assertSame(100, (int) $offer->fresh()->quantity);
        $this->assertSame(
            0,
            (int) Product::where('user_id', $this->doctor->id)
                ->where('product_master_id', $offer->product_master_id)->value('quantity')
        );
    }

    /** @test */
    public function rejecting_a_whole_order_return_changes_nothing()
    {
        $order = $this->deliveredOrder([['id' => $this->offer(10)->id, 'quantity' => 5]]);

        $this->requestWholeOrderReturn($order)->assertSuccessful();
        $this->decideWholeOrderReturn($order, 'rejected')->assertSuccessful();

        $this->assertEquals(50, $this->ledger()->total);
        $this->assertSame(0, OrderItemRefund::count());
        $this->assertSame('delivered', $order->fresh()->status);
    }

    /** @test */
    public function an_undelivered_order_has_nothing_to_return()
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => [['id' => $this->offer(10)->id, 'quantity' => 2]]])
            ->assertSuccessful();

        $order = Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->firstOrFail();

        $this->requestWholeOrderReturn($order)->assertSuccessful();
        $this->decideWholeOrderReturn($order, 'confirmed')->assertStatus(422);

        $this->assertSame(0, OrderItemRefund::count());
        $this->assertNull($this->ledger());
    }

    // ===================================================== H-4

    /** @test */
    public function a_line_quantity_can_be_changed_before_fulfilment_and_the_total_follows()
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => [['id' => $this->offer(10)->id, 'quantity' => 2]]])
            ->assertSuccessful();

        $order = Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->firstOrFail();
        $item = $order->orderItems()->first();

        $this->assertEquals(20, $item->line_total);

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/update-item/{$item->id}", ['quantity' => 5])
            ->assertSuccessful();

        $item->refresh();

        $this->assertSame(5, (int) $item->quantity);
        $this->assertEquals(10, $item->unit_price, 'The historical unit price is preserved.');
        $this->assertEquals(50, $item->line_total, 'The line total follows the quantity.');
        $this->assertEquals(50, $order->fresh()->total_order_price);
    }

    /**
     * After fulfilment the quantity is fixed: changing it would need a
     * controlled financial adjustment against a balance that may already be
     * settled or partly refunded.
     *
     * @test
     */
    public function a_line_quantity_cannot_be_changed_after_fulfilment()
    {
        $order = $this->deliveredOrder([['id' => $this->offer(10)->id, 'quantity' => 2]]);
        $item = $order->orderItems()->first();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/update-item/{$item->id}", ['quantity' => 5])
            ->assertStatus(422);

        $item->refresh();

        $this->assertSame(2, (int) $item->quantity, 'Unchanged.');
        $this->assertEquals(20, $item->line_total);
        $this->assertEquals(20, $this->ledger()->total, 'The ledger is unaffected.');
    }

    /** @test */
    public function a_quantity_change_keeps_the_line_arithmetically_consistent()
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => [['id' => $this->offer(12.50)->id, 'quantity' => 2]]])
            ->assertSuccessful();

        $order = Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->firstOrFail();
        $item = $order->orderItems()->first();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/update-item/{$item->id}", ['quantity' => 3])
            ->assertSuccessful();

        $item->refresh();

        $this->assertEquals(37.50, $item->line_total, 'unit_price x quantity, exactly.');
        $this->assertEquals(
            (float) $item->unit_price * (int) $item->quantity,
            (float) $item->line_total
        );
    }

    /** @test */
    public function another_doctor_cannot_change_a_line_quantity()
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => [['id' => $this->offer(10)->id, 'quantity' => 2]]])
            ->assertSuccessful();

        $order = Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->firstOrFail();
        $item = $order->orderItems()->first();

        $this->actingAs(User::factory()->doctor()->create(), 'sanctum')
            ->postJson("/api/order/update-item/{$item->id}", ['quantity' => 99])
            ->assertStatus(403);

        $this->assertSame(2, (int) $item->fresh()->quantity);
    }

    // ============================= administrative deletion vs financial return

    /**
     * Deleting an order is an administrative act, not a refund. Once a line
     * has been fulfilled there is financial history to protect.
     *
     * @test
     */
    public function a_fulfilled_order_cannot_be_administratively_deleted()
    {
        $order = $this->deliveredOrder([['id' => $this->offer(10)->id, 'quantity' => 2]]);

        $this->expectException(\App\Exceptions\Financial\FulfilmentException::class);

        app(\App\Services\Financial\OrderService::class)->delete($order->fresh());
    }
}
