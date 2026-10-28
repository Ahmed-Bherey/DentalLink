<?php

namespace Tests\Feature\Financial;

use App\Models\Financial\OrderExpense;
use App\Models\Financial\Payment;
use App\Models\Financial\Receipt;
use App\Models\User;
use App\Services\Financial\LedgerService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * STAGE 6 — financial invariants, validation, and API consistency.
 *
 * Companion to FinancialBaselineTest, which holds the inverted characterisation
 * tests. This file covers the invariants themselves rather than specific
 * historical bugs. Concurrency has its own file because it cannot run inside
 * RefreshDatabase's transaction.
 */
class MoneyIntegrityTest extends TestCase
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

    private function ledger(float $total, float $paid = 0): OrderExpense
    {
        return OrderExpense::factory()
            ->between($this->doctor, $this->supplier)
            ->withBalance($total, $paid)
            ->create();
    }

    private function pay($amount)
    {
        return $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $this->doctor->id,
                'paid' => $amount,
                'date' => now()->toDateString(),
            ]);
    }

    private function assertInvariantsHold(OrderExpense $ledger): void
    {
        $violations = app(LedgerService::class)->invariantViolations($ledger->refresh());

        $this->assertSame([], $violations, 'Ledger invariants broken: '.implode('; ', $violations));
    }

    // ===================================================== payment validation

    /** @test */
    public function a_zero_payment_is_rejected()
    {
        $this->ledger(1000);

        $this->pay(0)->assertStatus(422)->assertJsonValidationErrors('paid');

        $this->assertDatabaseCount('payments', 0);
    }

    /** @test */
    public function a_negative_payment_is_rejected()
    {
        $this->ledger(1000);

        $this->pay(-50)->assertStatus(422)->assertJsonValidationErrors('paid');

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Without an upper bound a larger number passes validation and then fails
     * against DECIMAL(12,2) at the database, surfacing as a 500 rather than a
     * field error.
     *
     * @test
     */
    public function a_payment_beyond_the_storable_range_is_rejected_by_validation()
    {
        $this->ledger(1000);

        $this->pay('99999999999999.99')->assertStatus(422)->assertJsonValidationErrors('paid');
    }

    /** @test */
    public function a_payment_against_a_fully_settled_balance_is_rejected()
    {
        $ledger = $this->ledger(100);
        $this->pay(100)->assertSuccessful();

        $this->pay(1)->assertStatus(422);

        $this->assertEquals(100, $ledger->refresh()->paid);
        $this->assertInvariantsHold($ledger);
    }

    /** @test */
    public function a_payment_against_a_doctor_with_no_ledger_is_rejected_rather_than_crashing()
    {
        // No OrderExpense row at all: nothing has ever been delivered.
        // The old code called ->update() on a null lookup result and fatalled.
        $this->pay(50)->assertStatus(422);

        $this->assertDatabaseCount('payments', 0);
    }

    // ==================================================== multiple payments

    /**
     * The example from the Stage 6 brief: 1000 total, three payments, correct
     * accumulation. Notably `paid` is NOT the latest payment.
     *
     * @test
     */
    public function many_payments_accumulate_and_leave_the_correct_remainder()
    {
        $ledger = $this->ledger(1000);

        foreach ([200, 300, 100] as $amount) {
            $this->pay($amount)->assertSuccessful();
        }

        $ledger->refresh();

        $this->assertEquals(600, $ledger->paid);
        $this->assertEquals(400, $ledger->remaining);
        // Every payment is retained individually.
        $this->assertDatabaseCount('payments', 3);
        $this->assertInvariantsHold($ledger);
    }

    /** @test */
    public function payment_history_survives_an_amendment()
    {
        $this->ledger(1000);
        $this->pay(200)->assertSuccessful();
        $this->pay(300)->assertSuccessful();

        $second = Payment::orderByDesc('id')->first();

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/payment/update/{$second->id}", [
                'paid' => 400,
                'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        // Amending must not remove a row.
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('payments', ['id' => $second->id, 'amount' => '300.00']);
    }

    // ======================================================== state machine

    /** @test */
    public function a_payment_awaiting_a_decision_cannot_be_amended_again()
    {
        $this->ledger(1000);
        $payment = Payment::factory()
            ->between($this->doctor, $this->supplier)
            ->of(200)
            ->pendingAmendment(300)
            ->create();

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/payment/update/{$payment->id}", [
                'paid' => 400,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422);

        $this->assertEquals('300.00', $payment->refresh()->requested_amount);
    }

    /** @test */
    public function a_settled_payment_has_no_pending_decision_to_make()
    {
        $this->ledger(1000);
        $payment = Payment::factory()->between($this->doctor, $this->supplier)->of(200)->create();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertStatus(422);
    }

    /** @test */
    public function rejecting_an_amendment_restores_the_original_amount()
    {
        $ledger = $this->ledger(1000);
        $payment = Payment::factory()
            ->between($this->doctor, $this->supplier)
            ->of(200)
            ->pendingAmendment(900)
            ->create();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'rejected'])
            ->assertSuccessful();

        $payment->refresh();

        $this->assertSame('confirmed', $payment->status);
        $this->assertEquals(200, $payment->amount, 'The original amount stands.');
        $this->assertNull($payment->requested_amount);
        $this->assertEquals(200, $ledger->refresh()->paid);
        $this->assertInvariantsHold($ledger);
    }

    /** @test */
    public function rejecting_a_void_request_leaves_the_payment_standing()
    {
        $ledger = $this->ledger(1000);
        $payment = Payment::factory()
            ->between($this->doctor, $this->supplier)
            ->of(200)
            ->pendingDeletion()
            ->create();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'rejected'])
            ->assertSuccessful();

        $this->assertSame('confirmed', $payment->refresh()->status);
        $this->assertEquals(200, $ledger->refresh()->paid);
        $this->assertInvariantsHold($ledger);
    }

    /**
     * An amendment must be measured against the balance with its OWN current
     * contribution released — otherwise raising 200 to 250 is compared against a
     * balance that still counts the original 200 and is wrongly refused.
     *
     * @test
     */
    public function an_amendment_may_use_the_room_its_own_payment_occupies()
    {
        $this->ledger(300);
        $this->pay(300)->assertSuccessful();

        $payment = Payment::first();

        // Nothing is outstanding, yet lowering this payment must still be allowed.
        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/payment/update/{$payment->id}", [
                'paid' => 250,
                'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        // And raising it beyond the total must not be.
        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/payment/update/{$payment->id}", [
                'paid' => 400,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }

    // ======================================================= precision

    /** @test */
    public function fractional_payments_accumulate_without_drift()
    {
        $ledger = $this->ledger(100);

        for ($i = 0; $i < 10; $i++) {
            $this->pay(0.10)->assertSuccessful();
        }

        $ledger->refresh();

        $this->assertEquals(1.00, $ledger->paid, 'Ten payments of 0.10 must total exactly 1.00.');
        $this->assertEquals(99.00, $ledger->remaining);
        $this->assertInvariantsHold($ledger);
    }

    /** @test */
    public function a_stored_amount_keeps_both_decimal_places()
    {
        $this->ledger(1000);
        $this->pay(99.99)->assertSuccessful();

        $this->assertDatabaseHas('payments', ['amount' => '99.99']);
    }

    // ================================================ database constraints

    /**
     * The application prevents negative money; this proves the database does
     * too, for everything that is not the application.
     *
     * A failure here may mean the server accepts CHECK syntax without enforcing
     * it (MySQL < 8.0.16), in which case the guarantee is illusory — which is
     * exactly why this is asserted rather than assumed.
     *
     * @test
     *
     * @dataProvider negativeMoneyCases
     */
    public function the_database_refuses_negative_money($table, $column, $row)
    {
        $this->expectException(QueryException::class);

        DB::table($table)->insert(array_merge($row(), [$column => -1]));
    }

    public function negativeMoneyCases(): array
    {
        return [
            'payments.amount' => ['payments', 'amount', fn () => [
                'doctor_id' => User::factory()->doctor()->create()->id,
                'supplier_id' => User::factory()->supplier()->create()->id,
            ]],
            'receipts.value' => ['receipts', 'value', fn () => [
                'user_id' => User::factory()->doctor()->create()->id,
                'name' => 'x',
            ]],
            'order_expenses.total' => ['order_expenses', 'total', fn () => [
                'doctor_id' => User::factory()->doctor()->create()->id,
                'supplier_id' => User::factory()->supplier()->create()->id,
                'paid' => 0,
                'remaining' => 0,
            ]],
        ];
    }

    // ================================================= API consistency

    /**
     * Every endpoint that reports a balance must report the SAME balance. Before
     * Stage 6 each resource computed it independently, with the stored column
     * commented out beside the calculation.
     *
     * @test
     */
    public function every_endpoint_reports_the_same_outstanding_balance()
    {
        $this->ledger(1000);
        $this->pay(250.50)->assertSuccessful();

        $fromPayments = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/payment/index')
            ->assertSuccessful()
            ->json('data.0.remaining');

        $fromDirectory = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/report/supplier/all')
            ->assertSuccessful()
            ->json('data.0.remaining');

        $this->assertEquals(749.50, $fromPayments);
        $this->assertEquals(
            $fromPayments,
            $fromDirectory,
            'Two endpoints disagree about the same obligation.'
        );
    }

    /** @test */
    public function the_directory_reports_paid_and_total_without_truncation()
    {
        $this->ledger(1000.25);
        $this->pay(100.75)->assertSuccessful();

        $row = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/report/supplier/all')
            ->assertSuccessful()
            ->json('data.0');

        $this->assertEquals(1000.25, $row['total']);
        $this->assertEquals(100.75, $row['paid']);
        $this->assertEquals(899.50, $row['remaining']);
    }

    // ========================================================== receipts

    /**
     * Receipts are NOT payment confirmations.
     *
     * The receipts table has user_id, name, value, img and date, and no link to
     * payments or orders of any kind. A receipt is a user's own expense
     * document, private to them (Stage 4 made it 404 to everyone else). It does
     * not duplicate a payment amount, so there is no payment/receipt
     * consistency to maintain — and voiding a payment cannot orphan one.
     *
     * Asserted rather than merely stated, so that if a future change couples the
     * two, this documentation fails instead of quietly going stale.
     *
     * @test
     */
    public function receipts_are_independent_of_payments()
    {
        foreach (['payment_id', 'order_id', 'supplier_id', 'doctor_id'] as $column) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('receipts', $column),
                "receipts.{$column} exists — receipts are now coupled to the payment domain."
            );
        }

        $ledger = $this->ledger(1000);
        $this->pay(200)->assertSuccessful();

        Receipt::factory()->for($this->supplier)->create(['value' => 200]);

        $payment = Payment::first();
        $payment->update(['status' => 'voided']);
        app(LedgerService::class)->recalculate($ledger);

        // A receipt is unaffected by a voided payment.
        $this->assertDatabaseCount('receipts', 1);
        $this->assertEquals(0, $ledger->refresh()->paid);
    }

    // ==================================================== Money primitive

    /** @test */
    public function money_arithmetic_is_exact_and_rounds_half_away_from_zero()
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('0.00', Money::sub('0.30', '0.30'));
        $this->assertSame('59.97', Money::mul('19.99', 3));
        $this->assertSame('11.00', Money::of('10.995'));
        $this->assertSame('10.99', Money::of('10.994'));
        $this->assertSame('-11.00', Money::of('-10.995'));
        $this->assertSame('0.00', Money::of(null));
        $this->assertSame(0, Money::cmp('1.000', '1.00'));
        $this->assertSame('0.00', Money::atLeastZero('-5.00'));
    }
}
