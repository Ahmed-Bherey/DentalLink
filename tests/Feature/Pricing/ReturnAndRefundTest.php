<?php

namespace Tests\Feature\Pricing;

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
 * STAGE 7 — returns, refunds, and the historical price they are settled at.
 *
 * THE BUSINESS RULE
 *
 * An approved return means the doctor has received the money back. So the
 * refund reduces BOTH the obligation and the amount considered paid:
 *
 *     Total 1000, Paid 1000, approved return 300
 *         -> Effective paid 700, Remaining 0
 *
 *     Total 100, Paid 60, approved return 30
 *         -> Obligation 70, Effective paid 30, Remaining 40
 *
 * THE PRICE
 *
 * A refund is always settled at the price on the ORDER LINE, never the
 * product's current price. Bought 5 at 10, supplier reprices to 12, return 2:
 * the refund is 20, not 24.
 */
class ReturnAndRefundTest extends TestCase
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

    // ------------------------------------------------------------- helpers

    private function offer(float $price, int $stock = 100): Product
    {
        return Product::factory()
            ->ownedBy($this->supplier)
            ->of(ProductMaster::factory()->create())
            ->pricedAt($price)
            ->create(['quantity' => $stock]);
    }

    /** Place and deliver an order, so the charge lands on the ledger. */
    private function buy(Product $offer, int $quantity): Order
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $offer->id, 'quantity' => $quantity]],
            ])->assertSuccessful();

        $order = Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->first();

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertSuccessful();

        return $order->fresh();
    }

    private function pay(float $amount)
    {
        return $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => $amount,
                'date' => now()->toDateString(),
            ]);
    }

    private function requestReturn(OrderItem $item, int $quantity)
    {
        return $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => $quantity]);
    }

    private function decideReturn(OrderItem $item, string $status)
    {
        return $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => $status]);
    }

    private function ledger(): OrderExpense
    {
        return OrderExpense::where('doctor_id', $this->doctor->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail();
    }

    private function assertInvariantsHold(): void
    {
        $violations = app(LedgerService::class)->invariantViolations($this->ledger());

        $this->assertSame([], $violations, 'Ledger invariants broken: '.implode('; ', $violations));
    }

    // ============================================ return quantity boundaries

    /** @test */
    public function a_return_request_cannot_exceed_the_purchased_quantity()
    {
        $order = $this->buy($this->offer(10), 5);
        $item = $order->orderItems()->first();

        $this->requestReturn($item, 6)->assertStatus(422);

        $this->assertSame(0, (int) $item->fresh()->pending_return_quantity);
    }

    /**
     * The cumulative rule. Previously `quantity` was decremented on approval, so
     * the ceiling moved with it and the total ever returned was never checked.
     *
     * @test
     */
    public function returns_cannot_cumulatively_exceed_the_purchased_quantity()
    {
        $order = $this->buy($this->offer(10), 5);
        $item = $order->orderItems()->first();

        $this->requestReturn($item, 2)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $this->assertSame(2, (int) $item->fresh()->returned_quantity);
        $this->assertSame(3, $item->fresh()->outstandingQuantity());

        // 3 more is exactly the remainder: allowed.
        $this->requestReturn($item->fresh(), 3)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $this->assertSame(5, (int) $item->fresh()->returned_quantity);
        $this->assertSame(0, $item->fresh()->outstandingQuantity());

        // Nothing is left to return.
        $this->requestReturn($item->fresh(), 1)->assertStatus(422);
    }

    /** @test */
    public function the_purchased_quantity_is_never_reduced_by_a_return()
    {
        $order = $this->buy($this->offer(10), 5);
        $item = $order->orderItems()->first();

        $this->requestReturn($item, 2)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $this->assertSame(5, (int) $item->fresh()->quantity, 'quantity is the immutable purchase.');
        $this->assertEquals(50, $item->fresh()->historicalLineTotal(), 'The original contract value stands.');
    }

    // ================================================ approval gates the money

    /** @test */
    public function a_return_request_does_not_change_the_balance_before_approval()
    {
        $this->buy($this->offer(10), 5);
        $item = OrderItem::first();

        $before = $this->ledger()->only(['total', 'paid', 'remaining']);

        $this->requestReturn($item, 2)->assertSuccessful();

        $this->assertEquals($before, $this->ledger()->only(['total', 'paid', 'remaining']));
        $this->assertSame(0, OrderItemRefund::count(), 'No refund exists until approval.');
        $this->assertInvariantsHold();
    }

    /** @test */
    public function a_rejected_return_leaves_the_balance_and_the_line_untouched()
    {
        $this->buy($this->offer(10), 5);
        $item = OrderItem::first();

        $this->requestReturn($item, 2)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'rejected')->assertSuccessful();

        $item->refresh();

        $this->assertSame('confirmed', $item->status);
        $this->assertSame(0, (int) $item->pending_return_quantity);
        $this->assertSame(0, (int) $item->returned_quantity);
        $this->assertEquals(50, $this->ledger()->total);
        $this->assertSame(0, OrderItemRefund::count());
    }

    /** @test */
    public function an_approved_return_reduces_the_obligation()
    {
        $this->buy($this->offer(10), 5);
        $item = OrderItem::first();

        $this->assertEquals(50, $this->ledger()->total);

        $this->requestReturn($item, 2)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $this->assertEquals(30, $this->ledger()->total, '50 - (2 x 10)');
        $this->assertEquals(30, $this->ledger()->remaining);
        $this->assertInvariantsHold();
    }

    // ==================================================== historical pricing

    /**
     * THE MANDATORY SCENARIO from the requirement.
     *
     * Bought 5 at 10 = 50. Supplier reprices to 12. Return 2.
     * The refund is 2 x 10 = 20, NOT 2 x 12 = 24.
     *
     * @test
     */
    public function a_refund_uses_the_historical_price_not_the_current_one()
    {
        $offer = $this->offer(10);
        $this->buy($offer, 5);
        $item = OrderItem::first();

        // The supplier raises the price AFTER the sale.
        $offer->update(['price' => 12]);

        $this->requestReturn($item, 2)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $refund = OrderItemRefund::firstOrFail();

        $this->assertEquals(10, $refund->unit_price, 'Settled at the price charged.');
        $this->assertEquals(20, $refund->amount, '2 x 10, not 2 x 12.');
        $this->assertEquals(30, $this->ledger()->total, 'Historical obligation: 50 - 20.');
        $this->assertInvariantsHold();
    }

    /**
     * And the same in the other direction — a price CUT must not shrink the
     * refund the supplier owes.
     *
     * @test
     */
    public function a_price_reduction_does_not_shrink_an_earlier_refund()
    {
        $offer = $this->offer(10);
        $this->buy($offer, 5);
        $item = OrderItem::first();

        $offer->update(['price' => 4]);

        $this->requestReturn($item, 2)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $this->assertEquals(20, OrderItemRefund::firstOrFail()->amount, '2 x 10, not 2 x 4.');
    }

    // ============================================ the specified paid/remaining

    /**
     * The requirement's first worked example, exactly.
     *
     *   Total 1000, Paid 1000, approved return 300
     *   -> Effective paid 700, Remaining 0
     *
     * @test
     */
    public function fully_paid_order_with_an_approved_return()
    {
        $offer = $this->offer(100);
        $this->buy($offer, 10);            // total 1000
        $this->pay(1000)->assertSuccessful();

        $this->assertEquals(1000, $this->ledger()->paid);
        $this->assertEquals(0, $this->ledger()->remaining);

        $item = OrderItem::first();
        $this->requestReturn($item, 3)->assertSuccessful();   // refund 300
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $ledger = $this->ledger();

        $this->assertEquals(700, $ledger->total, 'Obligation after the return.');
        $this->assertEquals(700, $ledger->paid, 'Effective paid: 1000 received, 300 given back.');
        $this->assertEquals(0, $ledger->remaining);
        $this->assertInvariantsHold();
    }

    /**
     * The requirement's second worked example, exactly.
     *
     *   Order 100, Paid 60, Return 30
     *   -> Adjusted obligation 70, Effective paid 30, Remaining 40
     *
     * @test
     */
    public function partially_paid_order_with_a_partial_return()
    {
        $offer = $this->offer(10);
        $this->buy($offer, 10);            // total 100
        $this->pay(60)->assertSuccessful();

        $item = OrderItem::first();
        $this->requestReturn($item, 3)->assertSuccessful();   // refund 30
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $ledger = $this->ledger();

        $this->assertEquals(70, $ledger->total, 'Adjusted obligation.');
        $this->assertEquals(30, $ledger->paid, 'Effective paid.');
        $this->assertEquals(40, $ledger->remaining);
        $this->assertInvariantsHold();
    }

    /** @test */
    public function an_unpaid_order_fully_returned_leaves_nothing_owed()
    {
        $offer = $this->offer(10);
        $this->buy($offer, 10);            // total 100, nothing paid

        $item = OrderItem::first();
        $this->requestReturn($item, 10)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $ledger = $this->ledger();

        $this->assertEquals(0, $ledger->total);
        $this->assertEquals(0, $ledger->paid, 'Nothing was paid, so nothing can be un-paid.');
        $this->assertEquals(0, $ledger->remaining);
        $this->assertInvariantsHold();
    }

    /**
     * Three successive returns totalling the whole order.
     *
     * @test
     */
    public function multiple_returns_accumulate_to_a_zero_obligation()
    {
        $offer = $this->offer(10);
        $this->buy($offer, 10);            // total 100
        $item = OrderItem::first();

        foreach ([2, 3, 5] as $quantity) {
            $this->requestReturn($item->fresh(), $quantity)->assertSuccessful();
            $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();
        }

        $this->assertSame(3, OrderItemRefund::count(), 'Each return is its own auditable event.');
        $this->assertEquals(0, $this->ledger()->total);
        $this->assertSame(10, (int) $item->fresh()->returned_quantity);

        // Nothing further can be returned.
        $this->requestReturn($item->fresh(), 1)->assertStatus(422);
        $this->assertInvariantsHold();
    }

    /**
     * Price change AND a return AND a payment, combined.
     *
     * @test
     */
    public function a_price_change_between_purchase_and_payment_changes_nothing()
    {
        $offer = $this->offer(10);
        $this->buy($offer, 10);            // total 100 at 10 each

        $this->pay(40)->assertSuccessful();

        // Supplier reprices mid-flight.
        $offer->update(['price' => 12]);

        $this->pay(60)->assertSuccessful();

        $ledger = $this->ledger();

        $this->assertEquals(100, $ledger->total, 'The order must not become 120.');
        $this->assertEquals(100, $ledger->paid);
        $this->assertEquals(0, $ledger->remaining);
        $this->assertInvariantsHold();
    }

    // ================================================= audit / stock effects

    /** @test */
    public function an_approved_return_records_who_approved_it_and_when()
    {
        $this->buy($this->offer(10), 5);
        $item = OrderItem::first();

        $this->requestReturn($item, 2)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $refund = OrderItemRefund::firstOrFail();

        $this->assertSame($this->supplier->id, (int) $refund->approved_by);
        $this->assertNotNull($refund->approved_at);
        $this->assertSame($this->doctor->id, (int) $refund->doctor_id);
        $this->assertSame($this->supplier->id, (int) $refund->supplier_id);
        $this->assertSame(2, (int) $refund->quantity);
    }

    /** @test */
    public function an_approved_return_moves_the_stock_back_to_the_supplier()
    {
        $offer = $this->offer(10, 100);
        $this->buy($offer, 5);

        $this->assertSame(95, (int) $offer->fresh()->quantity, 'Delivery took 5 out of supplier stock.');

        $item = OrderItem::first();
        $this->requestReturn($item, 2)->assertSuccessful();
        $this->decideReturn($item->fresh(), 'confirmed')->assertSuccessful();

        $this->assertSame(97, (int) $offer->fresh()->quantity, '2 came back.');
    }

    /**
     * Delivery gives the doctor an inventory row against the SAME canonical
     * product, rather than a name-matched copy.
     *
     * @test
     */
    public function delivery_creates_doctor_inventory_against_the_canonical_product()
    {
        $offer = $this->offer(10);
        $this->buy($offer, 5);

        $doctorStock = Product::where('user_id', $this->doctor->id)->firstOrFail();

        $this->assertSame(
            $offer->product_master_id,
            $doctorStock->product_master_id,
            'The doctor holds the same canonical product, not a duplicate of it.'
        );
        $this->assertSame(5, (int) $doctorStock->quantity);
    }
}
