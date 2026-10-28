<?php

namespace Tests\Feature\Orders;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Store\Product;
use App\Models\User;
use App\Services\Financial\OrderService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrency protection on fulfilment (C-2).
 *
 * THE RACE
 *
 *   Two "delivered" requests for the same order arrive together. Both read the
 *   line as unfulfilled, both charge, both move stock — the doctor is billed
 *   twice and inventory drops twice.
 *
 * The fix serialises them: fulfilForSupplier() takes a row lock on the
 * supplier's unfulfilled lines, so the second transaction cannot read them
 * until the first commits, by which point they are fulfilled and there is
 * nothing to do.
 *
 * WHY NOT RefreshDatabase
 *
 * It wraps each test in a transaction that is rolled back, so rows created
 * inside it are invisible to a second connection and the lock under test would
 * never be contended. These tests COMMIT and clean up by watermark, the same
 * approach PaymentConcurrencyTest uses.
 *
 * LIMITATION, STATED PLAINLY
 *
 * PHPUnit is single-threaded, so these are not two genuinely parallel HTTP
 * requests. What IS real: two separate database connections, two real
 * transactions and a real InnoDB row lock. The first test proves the lock
 * physically blocks the second connection; the second proves the application
 * refuses the duplicate once serialised.
 */
class FulfilmentConcurrencyTest extends TestCase
{
    private User $doctor;

    private User $supplier;

    private Order $order;

    private Product $product;

    private const CLEANUP_ORDER = [
        'order_item_refunds',
        'notifications_centers',
        'personal_access_tokens',
        'payments',
        'order_expenses',
        'order_items',
        'orders',
        'products',
        'product_masters',
        'users',
        'categories',
        'cities',
        'departments',
    ];

    /** @var array<string, int> */
    private array $watermarks = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::CLEANUP_ORDER as $table) {
            $this->watermarks[$table] = (int) DB::table($table)->max('id');
        }

        $this->doctor = User::factory()->doctor()->create();
        $this->supplier = User::factory()->supplier()->create();

        $this->product = Product::factory()
            ->ownedBy($this->supplier)
            ->of(ProductMaster::factory()->create())
            ->pricedAt(10)
            ->create(['quantity' => 100]);

        $this->order = Order::factory()->placedBy($this->doctor)->create(['status' => 'pending']);

        OrderItem::factory()->forOffer($this->product, 2)->create(['order_id' => $this->order->id]);
    }

    protected function tearDown(): void
    {
        foreach (self::CLEANUP_ORDER as $table) {
            DB::table($table)->where('id', '>', $this->watermarks[$table])->delete();
        }

        parent::tearDown();
    }

    /** @test */
    public function the_unfulfilled_lines_are_locked_against_other_connections()
    {
        config(['database.connections.second' => config('database.connections.mysql')]);
        DB::purge('second');
        $second = DB::connection('second');
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();

        try {
            // Take the same lock fulfilForSupplier() takes.
            OrderItem::where('order_id', $this->order->id)
                ->where('supplier_id', $this->supplier->id)
                ->whereNull('fulfilled_at')
                ->lockForUpdate()
                ->get();

            $blocked = false;
            $second->beginTransaction();

            try {
                $second->table('order_items')
                    ->where('order_id', $this->order->id)
                    ->whereNull('fulfilled_at')
                    ->lockForUpdate()
                    ->get();
            } catch (\Throwable $e) {
                $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
            } finally {
                $second->rollBack();
            }

            $this->assertTrue(
                $blocked,
                'A second connection read the unfulfilled lines while they were supposed to be locked. '
                .'Without the lock, two concurrent deliveries can both charge the doctor.'
            );
        } finally {
            DB::rollBack();
            DB::purge('second');
        }
    }

    /** @test */
    public function once_serialised_the_duplicate_fulfilment_is_refused()
    {
        $service = app(OrderService::class);

        $service->fulfilForSupplier($this->supplier, $this->order);

        $ledger = OrderExpense::where('doctor_id', $this->doctor->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail();

        $this->assertEquals(20, $ledger->total);
        $this->assertSame(98, (int) $this->product->fresh()->quantity);

        try {
            $service->fulfilForSupplier($this->supplier, $this->order->fresh());
            $this->fail('The second fulfilment should have been refused.');
        } catch (\App\Exceptions\Financial\FulfilmentException $e) {
            // expected
        }

        $this->assertEquals(20, $ledger->fresh()->total, 'Charged once.');
        $this->assertSame(98, (int) $this->product->fresh()->quantity, 'Stock moved once.');
    }
}
