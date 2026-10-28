<?php

namespace Tests\Feature\Financial;

use App\Models\Financial\OrderExpense;
use App\Models\Financial\Payment;
use App\Models\User;
use App\Services\Financial\LedgerService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * STAGE 6 — concurrency protection on the ledger.
 *
 * THE RACE
 *
 *   A doctor owes 100. Two payments of 80 arrive at the same moment.
 *   Both read remaining = 100, both conclude 80 fits, both commit.
 *   Result: paid = 160 against a total of 100.
 *
 * Neither request is individually wrong; the flaw is that both were allowed to
 * act on the same observation. The fix is the row lock taken by
 * LedgerService::lockFor(), which serialises the read-check-write sequence so
 * the second request observes the first request's result.
 *
 * WHY THIS FILE DOES NOT USE RefreshDatabase
 *
 * RefreshDatabase wraps each test in a transaction that is rolled back at the
 * end. Rows created inside it are invisible to any other connection, so a
 * second connection could not see the ledger to contend for it, and the lock
 * being tested would never be reached. These tests therefore COMMIT their data
 * and clean up explicitly in tearDown.
 *
 * LIMITATION, STATED PLAINLY
 *
 * PHPUnit is single-threaded, so these are not two genuinely parallel HTTP
 * requests. What IS genuine: two separate database connections, two real
 * transactions, and a real InnoDB row lock. The first test proves the lock
 * physically blocks another connection (by letting it hit a lock wait timeout);
 * the second proves the application logic refuses the second payment once
 * serialised. Together they cover both halves of the race. What they cannot
 * demonstrate is the interleaving itself, which would need a process-level
 * concurrency harness this project does not have.
 */
class PaymentConcurrencyTest extends TestCase
{
    private User $doctor;

    private User $supplier;

    private OrderExpense $ledger;

    /**
     * Tables this test commits into, in the order they must be DELETED from.
     *
     * Children before parents, because the Stage 5 foreign keys are RESTRICT and
     * will refuse a parent whose children still exist.
     */
    private const CLEANUP_ORDER = [
        'notifications_centers',
        'personal_access_tokens',
        'payments',
        'order_expenses',
        'users',
        'cities',
        'departments',
    ];

    /** @var array<string, int> table => highest id that existed before this test */
    private array $watermarks = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Everything created here is COMMITTED so a second connection can see
        // it, which means nothing is rolled back for us. Record a watermark per
        // table first so tearDown can remove exactly what this test added.
        //
        // Deleting only the users and payments is not enough: the user factory
        // also creates departments and cities, and leaving those behind breaks
        // later tests that assert on a fresh install.
        foreach (self::CLEANUP_ORDER as $table) {
            $this->watermarks[$table] = (int) DB::table($table)->max('id');
        }

        $this->doctor = User::factory()->doctor()->create();
        $this->supplier = User::factory()->supplier()->create();
        $this->ledger = OrderExpense::factory()
            ->between($this->doctor, $this->supplier)
            ->withBalance(100, 0)
            ->create();
    }

    protected function tearDown(): void
    {
        foreach (self::CLEANUP_ORDER as $table) {
            DB::table($table)->where('id', '>', $this->watermarks[$table])->delete();
        }

        parent::tearDown();
    }

    /**
     * A second connection genuinely cannot read the ledger row while it is held.
     *
     * Proven by giving the second connection a one-second lock wait timeout and
     * asserting that it times out. If lockFor() were not taking a lock, the
     * second connection would read the row immediately and this would fail.
     *
     * @test
     */
    public function the_ledger_row_is_locked_against_other_connections()
    {
        $ledgerService = app(LedgerService::class);

        // A genuinely separate connection to the same database.
        config(['database.connections.second' => config('database.connections.mysql')]);
        DB::purge('second');
        $second = DB::connection('second');
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();

        try {
            $ledgerService->lockFor($this->doctor->id, $this->supplier->id);

            $blocked = false;
            $second->beginTransaction();

            try {
                $second->table('order_expenses')
                    ->where('id', $this->ledger->id)
                    ->lockForUpdate()
                    ->first();
            } catch (\Throwable $e) {
                $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
            } finally {
                $second->rollBack();
            }

            $this->assertTrue(
                $blocked,
                'A second connection read the ledger row while it was supposed to be locked. '
                .'Without the lock, two concurrent payments can both be accepted against the same balance.'
            );
        } finally {
            DB::rollBack();
            DB::purge('second');
        }
    }

    /**
     * Once serialised by the lock, the second payment is refused.
     *
     * This is the outcome the lock exists to produce: with remaining = 100, two
     * payments of 80 cannot both succeed.
     *
     * @test
     */
    public function two_payments_that_would_together_exceed_the_balance_cannot_both_succeed()
    {
        $first = $this->postPayment(80);
        $second = $this->postPayment(80);

        $first->assertSuccessful();
        $second->assertStatus(422);

        $this->ledger->refresh();

        $this->assertEquals(80, $this->ledger->paid);
        $this->assertEquals(20, $this->ledger->remaining);
        $this->assertFalse($this->ledger->remaining < 0, 'The balance must never go negative.');

        $this->assertSame(
            [],
            app(LedgerService::class)->invariantViolations($this->ledger),
            'Ledger invariants must hold after a contended sequence.'
        );
    }

    /**
     * The ledger stays reconciled across a longer mixed sequence, including one
     * refusal in the middle.
     *
     * @test
     */
    public function the_ledger_reconciles_after_a_sequence_of_accepted_and_refused_payments()
    {
        $this->postPayment(30)->assertSuccessful();
        $this->postPayment(500)->assertStatus(422);
        $this->postPayment(45.55)->assertSuccessful();
        $this->postPayment(100)->assertStatus(422);
        $this->postPayment(24.45)->assertSuccessful();

        $this->ledger->refresh();

        $this->assertEquals(100, $this->ledger->paid);
        $this->assertEquals(0, $this->ledger->remaining);
        $this->assertSame(3, Payment::where('doctor_id', $this->doctor->id)->count());
        $this->assertSame([], app(LedgerService::class)->invariantViolations($this->ledger));
    }

    private function postPayment($amount)
    {
        return $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => $amount,
                'date' => now()->toDateString(),
            ]);
    }
}
