<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'supplier_id' => User::factory()->supplier(),
            'name' => $this->faker->unique()->words(2, true),
            'desc' => $this->faker->sentence(),
            'price' => 500,
            'active' => true,
        ];
    }

    public function ownedBy(User $supplier): static
    {
        return $this->state(fn () => ['supplier_id' => $supplier->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
