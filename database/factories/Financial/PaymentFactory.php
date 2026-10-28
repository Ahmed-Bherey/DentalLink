<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'doctor_id' => User::factory()->doctor(),
            'supplier_id' => User::factory()->supplier(),
            'amount' => 100,
            'requested_amount' => null,
            'date' => now()->toDateString(),
            'status' => 'confirmed',
        ];
    }

    public function between(User $doctor, User $supplier): static
    {
        return $this->state(fn () => [
            'doctor_id' => $doctor->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    public function of(float $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** An amendment awaiting the doctor confirmation step. */
    public function pendingAmendment(float $requested): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'requested_amount' => $requested,
        ]);
    }

    public function pendingDeletion(): static
    {
        return $this->withStatus('delete_pending');
    }
}
