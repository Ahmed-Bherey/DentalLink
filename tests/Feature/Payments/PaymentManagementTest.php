<?php

namespace Tests\Feature\Payments;

use App\Models\Financial\OrderExpense;
use App\Models\Financial\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baseline of the CURRENT payment behaviour (access and retrieval).
 *
 * The arithmetic itself is covered separately in
 * tests/Feature/Financial/FinancialBaselineTest.
 */
class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_supplier_can_record_a_payment_for_a_doctor(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        OrderExpense::factory()->between($doctor, $supplier)->withBalance(1000, 0)->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $doctor->id,
                'paid' => 250,
                'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', [
            'doctor_id' => $doctor->id,
            'supplier_id' => $supplier->id,
            'amount' => 250,
        ]);
    }

    /** @test */
    public function the_supplier_on_a_payment_is_taken_from_the_authenticated_user(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $victim = User::factory()->supplier()->create();

        OrderExpense::factory()->between($doctor, $supplier)->withBalance(1000, 0)->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $doctor->id,
                'supplier_id' => $victim->id,
                'paid' => 100,
                'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', ['supplier_id' => $supplier->id]);
        $this->assertDatabaseMissing('payments', ['supplier_id' => $victim->id]);
    }

    /** @test */
    public function payment_creation_validates_its_input(): void
    {
        $supplier = User::factory()->supplier()->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payment/store', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id', 'paid', 'date']);
    }

    /** @test */
    public function a_payment_amount_must_be_positive(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $doctor->id,
                'paid' => 0,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('paid');
    }

    /** @test */
    public function a_doctor_sees_only_their_own_payments(): void
    {
        $doctor = User::factory()->doctor()->create();
        $other = User::factory()->doctor()->create();

        Payment::factory()->count(2)->create(['doctor_id' => $doctor->id, 'status' => 'confirmed']);
        Payment::factory()->count(3)->create(['doctor_id' => $other->id, 'status' => 'confirmed']);

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/payment/index')
            ->assertSuccessful();

        $this->assertSame(2, $response->json('meta.total'));
    }

    /** @test */
    public function a_supplier_sees_only_their_own_payments(): void
    {
        $supplier = User::factory()->supplier()->create();
        $other = User::factory()->supplier()->create();

        Payment::factory()->count(4)->create(['supplier_id' => $supplier->id, 'status' => 'confirmed']);
        Payment::factory()->count(1)->create(['supplier_id' => $other->id, 'status' => 'confirmed']);

        $response = $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/payment/index')
            ->assertSuccessful();

        $this->assertSame(4, $response->json('meta.total'));
    }

    /** @test */
    public function a_doctor_sees_their_pending_payments(): void
    {
        $doctor = User::factory()->doctor()->create();

        Payment::factory()->create(['doctor_id' => $doctor->id, 'status' => 'pending']);
        Payment::factory()->create(['doctor_id' => $doctor->id, 'status' => 'delete_pending']);
        Payment::factory()->create(['doctor_id' => $doctor->id, 'status' => 'confirmed']);

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/payment/pending')
            ->assertSuccessful();

        $this->assertSame(2, $response->json('meta.total'));
    }

    /** @test */
    public function a_supplier_cannot_use_the_doctor_pending_payments_endpoint(): void
    {
        $supplier = User::factory()->supplier()->create();

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/payment/pending')
            ->assertStatus(403);
    }

    /** @test */
    public function the_owning_supplier_can_request_deletion_of_a_payment(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $payment = Payment::factory()->between($doctor, $supplier)->withStatus('confirmed')->create();

        $this->actingAs($supplier, 'sanctum')
            ->getJson("/api/payment/delete/{$payment->id}")
            ->assertSuccessful();

        $this->assertSame('delete_pending', $payment->fresh()->status);
    }

    /** @test */
    public function the_doctor_party_can_reject_a_deletion_request(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $payment = Payment::factory()->between($doctor, $supplier)->pendingDeletion()->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'rejected'])
            ->assertSuccessful();

        $this->assertSame('confirmed', $payment->fresh()->status);
    }

    /**
     * FIXED IN STAGE 6 (was: "approving a deletion hard-deletes the payment record").
     *
     * Approving a deletion called $payment->delete(). With no SoftDeletes and no
     * audit trail anywhere, the record that a payment had ever been made was
     * gone — while the balance moved in the same breath, leaving nothing to
     * explain why it had changed.
     *
     * The payment is now VOIDED: the row survives with its amount and dates
     * intact, and LedgerService stops counting it because only standing
     * statuses contribute to `paid`.
     *
     * @test
     */
    public function approving_a_deletion_voids_the_payment_but_keeps_the_record(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        OrderExpense::factory()->between($doctor, $supplier)->withBalance(1000, 200)->create();
        $payment = Payment::factory()->between($doctor, $supplier)->of(200)->pendingDeletion()->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertSuccessful();

        // The history survives, at its original amount.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'voided',
            'amount' => '200.00',
        ]);
    }

    /**
     * A voided payment must stop counting toward the balance — otherwise the
     * record would be preserved but the reversal would not take effect.
     *
     * @test
     */
    public function a_voided_payment_no_longer_counts_toward_the_balance(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $ledger = OrderExpense::factory()->between($doctor, $supplier)->withBalance(1000, 0)->create();
        $payment = Payment::factory()->between($doctor, $supplier)->of(200)->pendingDeletion()->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertSuccessful();

        $ledger->refresh();

        $this->assertEquals(0, $ledger->paid);
        $this->assertEquals(1000, $ledger->remaining);
    }

    /**
     * A voided payment must also disappear from the listings, so preserving the
     * record does not change what either party sees.
     *
     * @test
     */
    public function a_voided_payment_is_hidden_from_the_payment_listing(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        OrderExpense::factory()->between($doctor, $supplier)->withBalance(1000, 0)->create();
        $payment = Payment::factory()->between($doctor, $supplier)->of(200)->pendingDeletion()->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertSuccessful();

        $listed = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/payment/index')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame([], $listed, 'A voided payment must not appear in the listing.');
    }
}
