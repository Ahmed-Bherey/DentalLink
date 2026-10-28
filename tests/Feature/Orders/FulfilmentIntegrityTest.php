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
 * Regression tests for the order-fulfilment defects (C-1, C-2, C-3, H-3).
 *
 * Each group starts from the scenario that produced the wrong answer before
 * the fix, so a regression reproduces the original bug rather than merely
 * failing an assertion.
 */
class FulfilmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $supplierA;

    private User $supplierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = User::factory()->doctor()->create();
        $this->supplierA = User::factory()->supplier()->create();
        $this->supplierB = User::factory()->supplier()->create();
    }

    // ------------------------------------------------------------- helpers

    private function offer(User $supplier, float $price, int $stock = 100): Product
    {
        return Product::factory()
            ->ownedBy($supplier)
            ->of(ProductMaster::factory()->create())
            ->pricedAt($price)
            ->create(['quantity' => $stock]);
    }

    private function placeOrder(array $lines): Order
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => $lines])
            ->assertSuccessful();

        return Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->firstOrFail();
    }

    private function fulfil(User $supplier, Order $order)
    {
        return $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered']);
    }

    private function ledgerFor(User $supplier): ?OrderExpense
    {
        return OrderExpense::where('doctor_id', $this->doctor->id)
            ->where('supplier_id', $supplier->id)
            ->first();
    }

    private function assertInvariantsHold(?OrderExpense $ledger): void
    {
        if ($ledger === null) {
            return;
        }

        $violations = app(LedgerService::class)->invariantViolations($ledger->refresh());

        $this->assertSame([], $violations, 'Ledger invariants broken: '.implode('; ', $violations));
    }

    // =========================================================== C-1

    /**
     * Previously the whole order total was charged to whoever delivered it,
     * and the other supplier got no ledger row at all.
     *
     * @test
     */
    public function each_supplier_is_charged_only_for_their_own_lines()
    {
        $a = $this->offer($this->supplierA, 10);   // 2 x 10 = 20
        $b = $this->offer($this->supplierB, 50);   // 1 x 50 = 50

        $order = $this->placeOrder([
            ['id' => $a->id, 'quantity' => 2],
            ['id' => $b->id, 'quantity' => 1],
        ]);

        $this->fulfil($this->supplierA, $order)->assertSuccessful();

        $this->assertEquals(20, $this->ledgerFor($this->supplierA)->total, 'A sold 20, not 70.');
        $this->assertNull($this->ledgerFor($this->supplierB), 'B has not delivered, so B has no charge yet.');

        $this->fulfil($this->supplierB, $order)->assertSuccessful();

        $this->assertEquals(20, $this->ledgerFor($this->supplierA)->total, 'A is unchanged by B delivering.');
        $this->assertEquals(50, $this->ledgerFor($this->supplierB)->total);

        $this->assertInvariantsHold($this->ledgerFor($this->supplierA));
        $this->assertInvariantsHold($this->ledgerFor($this->supplierB));
    }

    /** @test */
    public function a_supplier_only_moves_their_own_stock()
    {
        $a = $this->offer($this->supplierA, 10, 100);
        $b = $this->offer($this->supplierB, 50, 100);

        $order = $this->placeOrder([
            ['id' => $a->id, 'quantity' => 2],
            ['id' => $b->id, 'quantity' => 1],
        ]);

        $this->fulfil($this->supplierA, $order)->assertSuccessful();

        $this->assertSame(98, (int) $a->fresh()->quantity, "A's stock moved.");
        $this->assertSame(100, (int) $b->fresh()->quantity, "B's stock must NOT move when A delivers.");

        // The doctor receives only what was actually delivered.
        $this->assertTrue(
            Product::where('user_id', $this->doctor->id)
                ->where('product_master_id', $a->product_master_id)->exists()
        );
        $this->assertFalse(
            Product::where('user_id', $this->doctor->id)
                ->where('product_master_id', $b->product_master_id)->exists(),
            "The doctor must not receive B's goods before B delivers."
        );
    }

    /** @test */
    public function an_order_is_only_marked_delivered_once_every_line_is_fulfilled()
    {
        $a = $this->offer($this->supplierA, 10);
        $b = $this->offer($this->supplierB, 50);

        $order = $this->placeOrder([
            ['id' => $a->id, 'quantity' => 1],
            ['id' => $b->id, 'quantity' => 1],
        ]);

        $this->fulfil($this->supplierA, $order)->assertSuccessful();
        $this->assertSame('preparing', $order->fresh()->status, 'Partially fulfilled.');

        $this->fulfil($this->supplierB, $order)->assertSuccessful();
        $this->assertSame('delivered', $order->fresh()->status, 'Fully fulfilled.');
    }

    /** @test */
    public function a_supplier_with_no_lines_on_the_order_cannot_fulfil_it()
    {
        $a = $this->offer($this->supplierA, 10);
        $order = $this->placeOrder([['id' => $a->id, 'quantity' => 1]]);

        $outsider = User::factory()->supplier()->create();

        $this->fulfil($outsider, $order)->assertStatus(403);

        $this->assertNull($this->ledgerFor($outsider));
    }

    // =========================================================== C-2

    /**
     * Previously a second "delivered" call charged again and moved stock again.
     *
     * @test
     */
    public function fulfilling_the_same_order_twice_does_not_charge_twice()
    {
        $a = $this->offer($this->supplierA, 10, 100);
        $order = $this->placeOrder([['id' => $a->id, 'quantity' => 2]]);

        $this->fulfil($this->supplierA, $order)->assertSuccessful();

        $totalAfterFirst = $this->ledgerFor($this->supplierA)->total;
        $stockAfterFirst = (int) $a->fresh()->quantity;
        $doctorStock = (int) Product::where('user_id', $this->doctor->id)
            ->where('product_master_id', $a->product_master_id)->value('quantity');

        // Second attempt: refused, with nothing changed.
        $this->fulfil($this->supplierA, $order)->assertStatus(422);

        $this->assertEquals($totalAfterFirst, $this->ledgerFor($this->supplierA)->total, 'No second charge.');
        $this->assertSame($stockAfterFirst, (int) $a->fresh()->quantity, 'No second stock movement.');
        $this->assertSame(
            $doctorStock,
            (int) Product::where('user_id', $this->doctor->id)
                ->where('product_master_id', $a->product_master_id)->value('quantity'),
            'The doctor is not given the goods twice.'
        );
        $this->assertInvariantsHold($this->ledgerFor($this->supplierA));
    }

    /** @test */
    public function repeated_fulfilment_attempts_never_accumulate()
    {
        $a = $this->offer($this->supplierA, 25, 50);
        $order = $this->placeOrder([['id' => $a->id, 'quantity' => 4]]);

        $this->fulfil($this->supplierA, $order)->assertSuccessful();

        foreach (range(1, 4) as $_) {
            $this->fulfil($this->supplierA, $order)->assertStatus(422);
        }

        $this->assertEquals(100, $this->ledgerFor($this->supplierA)->total, '4 x 25, once.');
        $this->assertSame(46, (int) $a->fresh()->quantity);
    }

    /** @test */
    public function a_line_records_when_it_was_fulfilled()
    {
        $a = $this->offer($this->supplierA, 10);
        $order = $this->placeOrder([['id' => $a->id, 'quantity' => 1]]);

        $this->assertNull($order->orderItems()->first()->fulfilled_at);

        $this->fulfil($this->supplierA, $order)->assertSuccessful();

        $this->assertNotNull($order->orderItems()->first()->fulfilled_at);
    }

    // =========================================================== C-3

    /**
     * The worst of the three: approving a return on goods that were never
     * delivered reduced BOTH the charge and the effective paid amount, wiping
     * real receivable off an unrelated settled balance.
     *
     * @test
     */
    public function a_return_cannot_be_requested_on_goods_that_were_never_delivered()
    {
        $a = $this->offer($this->supplierA, 10);
        $order = $this->placeOrder([['id' => $a->id, 'quantity' => 4]]);
        $item = $order->orderItems()->first();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 2])
            ->assertStatus(422);

        $this->assertSame(0, (int) $item->fresh()->pending_return_quantity);
        $this->assertSame(0, OrderItemRefund::count());
    }

    /**
     * The exact scenario from the risk assessment, with the figures it produced.
     *
     * @test
     */
    public function an_undelivered_return_cannot_erase_a_settled_balance()
    {
        // Order X — delivered and paid in full.
        $x = $this->offer($this->supplierA, 100);
        $orderX = $this->placeOrder([['id' => $x->id, 'quantity' => 10]]);
        $this->fulfil($this->supplierA, $orderX)->assertSuccessful();

        $this->actingAs($this->supplierA, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => 1000,
                'date' => now()->toDateString(),
            ])->assertSuccessful();

        $this->assertEquals(1000, $this->ledgerFor($this->supplierA)->total);
        $this->assertEquals(1000, $this->ledgerFor($this->supplierA)->paid);

        // Order Y — placed but never delivered.
        $y = $this->offer($this->supplierA, 50);
        $orderY = $this->placeOrder([['id' => $y->id, 'quantity' => 4]]);
        $itemY = $orderY->orderItems()->first();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$itemY->id}/return", ['quantity' => 4])
            ->assertStatus(422);

        $ledger = $this->ledgerFor($this->supplierA);

        $this->assertEquals(1000, $ledger->total, 'The settled balance is untouched.');
        $this->assertEquals(1000, $ledger->paid, 'The 1000 actually received still counts.');
        $this->assertSame(0, OrderItemRefund::count());
        $this->assertInvariantsHold($ledger);
    }

    /** @test */
    public function a_return_on_a_delivered_line_still_works_normally()
    {
        $a = $this->offer($this->supplierA, 10);
        $order = $this->placeOrder([['id' => $a->id, 'quantity' => 5]]);
        $this->fulfil($this->supplierA, $order)->assertSuccessful();
        $item = $order->orderItems()->first();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 2])
            ->assertSuccessful();

        $this->actingAs($this->supplierA, 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => 'confirmed'])
            ->assertSuccessful();

        $this->assertEquals(30, $this->ledgerFor($this->supplierA)->total, '50 - (2 x 10).');
        $this->assertSame(2, (int) $item->fresh()->returned_quantity);
        $this->assertInvariantsHold($this->ledgerFor($this->supplierA));
    }

    /**
     * On a partially fulfilled order, only the delivered lines may be returned.
     *
     * @test
     */
    public function on_a_partially_fulfilled_order_only_delivered_lines_are_returnable()
    {
        $a = $this->offer($this->supplierA, 10);
        $b = $this->offer($this->supplierB, 50);

        $order = $this->placeOrder([
            ['id' => $a->id, 'quantity' => 2],
            ['id' => $b->id, 'quantity' => 1],
        ]);

        $this->fulfil($this->supplierA, $order)->assertSuccessful();

        $lineA = $order->orderItems()->where('supplier_id', $this->supplierA->id)->first();
        $lineB = $order->orderItems()->where('supplier_id', $this->supplierB->id)->first();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$lineA->id}/return", ['quantity' => 1])
            ->assertSuccessful();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$lineB->id}/return", ['quantity' => 1])
            ->assertStatus(422);
    }

    // =========================================================== H-3

    /** @test */
    public function fulfilment_is_refused_when_stock_is_insufficient()
    {
        $a = $this->offer($this->supplierA, 10, 1);
        $order = $this->placeOrder([['id' => $a->id, 'quantity' => 5]]);

        $this->fulfil($this->supplierA, $order)->assertStatus(422);

        $this->assertSame(1, (int) $a->fresh()->quantity, 'Stock untouched.');
        $this->assertNull($this->ledgerFor($this->supplierA), 'No charge was made.');
        $this->assertNull($order->orderItems()->first()->fulfilled_at);
    }

    /** @test */
    public function fulfilment_succeeds_when_stock_is_exactly_sufficient()
    {
        $a = $this->offer($this->supplierA, 10, 5);
        $order = $this->placeOrder([['id' => $a->id, 'quantity' => 5]]);

        $this->fulfil($this->supplierA, $order)->assertSuccessful();

        $this->assertSame(0, (int) $a->fresh()->quantity);
        $this->assertEquals(50, $this->ledgerFor($this->supplierA)->total);
    }

    /**
     * A short line must roll the WHOLE operation back — no partial charge, no
     * partial stock movement from the lines that would have succeeded.
     *
     * @test
     */
    public function a_short_line_rolls_back_the_entire_fulfilment()
    {
        $ok = $this->offer($this->supplierA, 10, 100);
        $short = $this->offer($this->supplierA, 20, 1);

        $order = $this->placeOrder([
            ['id' => $ok->id, 'quantity' => 2],
            ['id' => $short->id, 'quantity' => 5],
        ]);

        $this->fulfil($this->supplierA, $order)->assertStatus(422);

        $this->assertSame(100, (int) $ok->fresh()->quantity, 'The healthy line was rolled back too.');
        $this->assertSame(1, (int) $short->fresh()->quantity);
        $this->assertNull($this->ledgerFor($this->supplierA));
        $this->assertSame(
            0,
            OrderItem::where('order_id', $order->id)->whereNotNull('fulfilled_at')->count(),
            'No line is left marked fulfilled.'
        );
        $this->assertSame(
            0,
            Product::where('user_id', $this->doctor->id)->count(),
            'The doctor received nothing.'
        );
    }

    /** @test */
    public function the_database_refuses_negative_product_stock()
    {
        $a = $this->offer($this->supplierA, 10, 5);

        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('products')
            ->where('id', $a->id)
            ->update(['quantity' => -1]);
    }
}
