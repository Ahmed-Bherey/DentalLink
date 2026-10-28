<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->doctor(),
            'name' => $this->faker->words(2, true),
            'value' => 250,
            'img' => null,
            'date' => now()->toDateString(),
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
