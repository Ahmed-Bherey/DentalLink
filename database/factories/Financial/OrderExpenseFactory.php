<?php

namespace Database\Factories\Financial;

use App\Models\Financial\OrderExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderExpense>
 *
 * This is the running balance between one doctor and one supplier.
 *
 * NOTE: total/paid/remaining are FLOAT columns mutated by read-modify-write,
 * and there is no unique key on (doctor_id, supplier_id).
 * => FINANCIAL DEFECTS — DEFERRED TO FINANCIAL LEDGER STAGE.
 */
class OrderExpenseFactory extends Factory
{
    protected $model = OrderExpense::class;

    public function definition(): array
    {
        return [
            'doctor_id' => User::factory()->doctor(),
            'supplier_id' => User::factory()->supplier(),
            'total' => 0,
            'paid' => 0,
            'remaining' => 0,
        ];
    }

    public function between(User $doctor, User $supplier): static
    {
        return $this->state(fn () => [
            'doctor_id' => $doctor->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    /** Sets a correct opening balance so tests can observe how the app mutates it. */
    public function withBalance(float $total, float $paid): static
    {
        return $this->state(fn () => [
            'total' => $total,
            'paid' => $paid,
            'remaining' => $total - $paid,
        ]);
    }
}
