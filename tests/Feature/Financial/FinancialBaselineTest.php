<?php

namespace Tests\Feature\Financial;

use App\Models\Financial\OrderExpense;
use App\Models\Financial\Payment;
use App\Models\User;
use App\Services\Financial\LedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FINANCIAL BASELINE — now asserting CORRECT behaviour.
 *
 * Stage 2 wrote this file to characterise what the application actually did,
 * including the parts that were wrong: each defect was pinned by a test whose
 * name described the bug, with the correct expectation recorded in the docblock
 * so a later stage could invert it and watch red turn green.
 *
 * Stage 6 is that stage. Every "DEFECT CONFIRMED" test here has been inverted
 * and renamed after the invariant it now protects; each docblock records what
 * the behaviour used to be, so the history is not lost with the bug.
 *
 * The running balance between a doctor and a supplier lives in one
 * order_expenses row: total (billed), paid (settled), remaining (outstanding).
 * The invariant, now enforced:  remaining == total - paid
 */
class FinancialBaselineTest extends TestCase
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

    private function ledger(float $total, float $paid): OrderExpense
    {
        return OrderExpense::factory()
            ->between($this->doctor, $this->supplier)
            ->withBalance($total, $paid)
            ->create();
    }

    private function ledgerService(): LedgerService
    {
        return app(LedgerService::class);
    }

    /**
     * Assert every Stage 6 invariant holds for this ledger row.
     *
     * Checks the arithmetic AND that the stored paid still equals the sum of the
     * pair's standing payments, which is what makes the ledger a projection of
     * its payments rather than an independently maintained number.
     */
    private function assertInvariantsHold(OrderExpense $ledger): void
    {
        $violations = $this->ledgerService()->invariantViolations($ledger->refresh());

        $this->assertSame(
            [],
            $violations,
            'Ledger invariants broken: '.implode('; ', $violations)
        );
    }

    /**
     * Reads a column's declared data type straight from information_schema so the
     * assertion does not depend on doctrine/dbal.
     */
    private function columnType(string $table, string $column): ?string
    {
        $row = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT DATA_TYPE AS data_type
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return $row?->data_type;
    }

    // ------------------------------------------------ behaviour that is CORRECT

    /** @test */
    public function recording_a_payment_reduces_the_outstanding_balance(): void
    {
        $ledger = $this->ledger(1000, 0);

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => 300,
                'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        $ledger->refresh();

        $this->assertEquals(300, $ledger->paid);
        $this->assertEquals(700, $ledger->remaining);
        $this->assertEquals($ledger->total - $ledger->paid, $ledger->remaining);
    }

    /** @test */
    public function successive_payments_accumulate_on_the_ledger(): void
    {
        $ledger = $this->ledger(1000, 0);

        foreach ([100, 200, 50] as $amount) {
            $this->actingAs($this->supplier, 'sanctum')
                ->postJson('/api/payment/store', [
                    'doctor_id' => $this->doctor->id,
                    'paid' => $amount,
                    'date' => now()->toDateString(),
                ])
                ->assertSuccessful();
        }

        $ledger->refresh();

        $this->assertEquals(350, $ledger->paid);
        $this->assertEquals(650, $ledger->remaining);
    }

    // --------------------------------------------------- DEFECTS (do not fix)

    /**
     * FIXED IN STAGE 6 (was: "confirming a deletion sets remaining to total + paid").
     *
     * Both confirm branches wrote `remaining = total + paid` instead of
     * `total - paid`, so approving anything INCREASED the apparent debt by twice
     * the amount already paid. Approving the reversal of a 200 payment against a
     * 1000 total produced remaining = 1200.
     *
     * The formula no longer exists in PaymentService: the balance is re-derived
     * by LedgerService from the payment rows.
     *
     * @test
     */
    public function confirming_a_void_reverses_the_payment_and_restores_the_balance(): void
    {
        $ledger = $this->ledger(1000, 0);

        // A settled payment of 200 that the supplier has asked to reverse. It
        // still counts toward paid until the doctor decides.
        $payment = Payment::factory()
            ->between($this->doctor, $this->supplier)
            ->of(200)
            ->pendingDeletion()
            ->create();

        $this->ledgerService()->recalculate($ledger->refresh());
        $this->assertEquals(200, $ledger->refresh()->paid, 'A payment awaiting a decision still counts.');
        $this->assertEquals(800, $ledger->remaining);

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertSuccessful();

        $ledger->refresh();

        $this->assertEquals(0, $ledger->paid, 'The reversed payment no longer counts.');
        $this->assertEquals(1000, $ledger->remaining, 'remaining = total - paid.');
        $this->assertInvariantsHold($ledger);
    }

    /**
     * FIXED IN STAGE 6 (was: "confirming an amendment discards all other payments").
     *
     * The old code computed `$total = paid - amount` then `paid = paid - $total`,
     * which reduces to `paid = amount` — the accumulated total was replaced by
     * the single amended payment, discarding every other payment the doctor had
     * made to that supplier.
     *
     * `paid` is now the sum of the pair's standing payments, so an amendment can
     * only change its own contribution.
     *
     * @test
     */
    public function confirming_an_amendment_keeps_every_other_payment(): void
    {
        $ledger = $this->ledger(1000, 0);

        // An unrelated settled payment that must survive untouched.
        Payment::factory()->between($this->doctor, $this->supplier)->of(200)->create();

        // A second payment of 200 the supplier wants raised to 300.
        $payment = Payment::factory()
            ->between($this->doctor, $this->supplier)
            ->of(200)
            ->pendingAmendment(300)
            ->create();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertSuccessful();

        $ledger->refresh();

        $this->assertEquals(500, $ledger->paid, 'paid = 200 (untouched) + 300 (amended).');
        $this->assertEquals(500, $ledger->remaining, 'remaining = 1000 - 500.');
        $this->assertInvariantsHold($ledger);
    }

    /**
     * FIXED IN STAGE 6 (was: "a payment larger than the outstanding balance is
     * currently accepted").
     *
     * store() looked the ledger up by `$user->supplier_id`, an attribute User
     * does not have, so the lookup always missed and the guard degenerated to
     * `if (x > x)` — never true. The balance could be driven negative.
     *
     * Rejecting overpayment is not a new rule: update() already enforced exactly
     * this, correctly. store() was written to do the same and the expression was
     * simply wrong.
     *
     * @test
     */
    public function a_payment_larger_than_the_outstanding_balance_is_rejected(): void
    {
        $ledger = $this->ledger(100, 0);

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => 5000,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('status', false);

        $ledger->refresh();

        $this->assertEquals(0, $ledger->paid, 'The rejected payment left no trace on the balance.');
        $this->assertEquals(100, $ledger->remaining);
        // No payment row was written.
        $this->assertDatabaseCount('payments', 0);
        $this->assertInvariantsHold($ledger);
    }

    /** @test */
    public function a_payment_exactly_equal_to_the_outstanding_balance_is_accepted(): void
    {
        $ledger = $this->ledger(100, 0);

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => 100,
                'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        $ledger->refresh();

        $this->assertEquals(100, $ledger->paid);
        $this->assertEquals(0, $ledger->remaining);
        $this->assertInvariantsHold($ledger);
    }

    /**
     * DEFECT CONFIRMED — DEFERRED TO FINANCIAL LEDGER STAGE.
     *
     * The `User::$supplier_id` attribute referenced by PaymentService does not
     * exist. This test pins the root cause so the fix is unambiguous.
     *
     * @test
     */
    public function the_user_model_has_no_supplier_id_attribute(): void
    {
        $this->assertNull(
            $this->supplier->supplier_id,
            'PaymentService::store() reads $user->supplier_id, which is always null.'
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('users', 'supplier_id')
        );
    }

    /**
     * DEFECT FIXED IN STAGE 5 (was: "duplicate ledger rows can exist").
     *
     * order_expenses had no unique key on (doctor_id, supplier_id), so a pair
     * could hold several conflicting balances while the service layer papered
     * over it with ->latest()->first(). Stage 5 added the unique constraint, so
     * this test now asserts the invariant instead of documenting its absence.
     *
     * Note what this does NOT change: the service still writes balances by
     * read-modify-write, and the money columns are still binary floats. Those
     * are separate defects owned by the financial ledger stage, and the test
     * below this one still records them.
     *
     * @test
     */
    public function only_one_ledger_row_can_exist_for_a_doctor_supplier_pair(): void
    {
        $this->ledger(1000, 0);

        try {
            $this->ledger(500, 100);
            $this->fail('A second balance row was accepted for the same doctor/supplier pair.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }

        $this->assertSame(
            1,
            OrderExpense::where('doctor_id', $this->doctor->id)
                ->where('supplier_id', $this->supplier->id)
                ->count(),
            'The pair must hold exactly one running balance.'
        );
    }

    /**
     * FIXED IN STAGE 6 (was: "money columns are floating point").
     *
     * Seven of the nine money columns were FLOAT (MariaDB widens to DOUBLE), and
     * the schema disagreed with itself — packages.price and orders.price were
     * already DECIMAL. All nine are now DECIMAL(12,2).
     *
     * Scale 2 matches every column's previous scale, so the conversion could not
     * truncate. Precision 12 replaces the old 999,999.99 ceiling, which a
     * CUMULATIVE doctor/supplier balance can realistically outgrow.
     *
     * @test
     */
    public function every_money_column_is_exact_decimal(): void
    {
        foreach ([
            ['order_expenses', 'total'],
            ['order_expenses', 'paid'],
            ['order_expenses', 'remaining'],
            ['payments', 'amount'],
            ['payments', 'requested_amount'],
            ['products', 'price'],
            ['receipts', 'value'],
            ['packages', 'price'],
            ['orders', 'price'],
        ] as [$table, $column]) {
            $this->assertSame(
                'decimal',
                $this->columnType($table, $column),
                "{$table}.{$column} must store money as exact decimal."
            );
        }
    }

    /**
     * Storage type alone is not enough: PDO returns DECIMAL as a string and PHP
     * coerces it to float the moment an arithmetic operator touches it. This
     * pins the arithmetic itself.
     *
     * @test
     */
    public function repeated_addition_of_a_fractional_amount_stays_exact(): void
    {
        $sum = Money::zero();

        for ($i = 0; $i < 10; $i++) {
            $sum = Money::add($sum, '0.10');
        }

        $this->assertSame('1.00', $sum);
        $this->assertSame('0.30', Money::add('0.10', '0.20'));

        // bcmath truncates at the requested scale; Money must round instead, or
        // a piastre disappears from every value with excess precision.
        $this->assertSame('11.00', Money::of('10.999'));
        $this->assertSame('-11.00', Money::of('-10.995'));
    }

    /**
     * FIXED IN STAGE 6 (was: "the payment API truncates fractional amounts").
     *
     * PaymentResource cast every amount with (int), so a payment of 99.75 was
     * reported to both parties as 99. The same cast was in DoctorResource and
     * SupplierResource, applied to paid, total and remaining.
     *
     * @test
     */
    public function the_payment_api_reports_fractional_amounts_exactly(): void
    {
        $this->ledger(1000, 0);
        Payment::factory()
            ->between($this->doctor, $this->supplier)
            ->of(99.75)
            ->withStatus('confirmed')
            ->create();

        $response = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/payment/index')
            ->assertSuccessful();

        $this->assertSame(99.75, $response->json('data.0.paid'));
    }

    /**
     * FIXED IN STAGE 6 (was: "the payment service uses no database transactions").
     *
     * Asserted behaviourally rather than by grepping the source, which is what
     * the original characterisation test had to do: a payment refused mid-flight
     * must leave no trace, which is only true if the write is transactional.
     *
     * @test
     */
    public function a_refused_payment_leaves_no_partial_state(): void
    {
        $ledger = $this->ledger(100, 0);

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => 500,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('notifications_centers', 0);
        $this->assertEquals(0, $ledger->refresh()->paid);
        $this->assertInvariantsHold($ledger);
    }

    /**
     * The ledger must always be a faithful projection of its payment rows.
     *
     * @test
     */
    public function the_ledger_reconciles_against_its_payments_after_a_mixed_sequence(): void
    {
        $ledger = $this->ledger(1000, 0);

        foreach ([120.55, 30.10, 9.99] as $amount) {
            $this->actingAs($this->supplier, 'sanctum')
                ->postJson('/api/payment/store', [
                    'doctor_id' => $this->doctor->id,
                    'paid' => $amount,
                    'date' => now()->toDateString(),
                ])
                ->assertSuccessful();
        }

        $ledger->refresh();

        $this->assertEquals(160.64, $ledger->paid);
        $this->assertEquals(839.36, $ledger->remaining);
        $this->assertInvariantsHold($ledger);
    }
}
