<?php

namespace Database\Factories\Catalogue;

use App\Models\Catalogue\ProductMaster;
use App\Models\General\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductMaster>
 */
class ProductMasterFactory extends Factory
{
    protected $model = ProductMaster::class;

    public function definition(): array
    {
        return [
            // Unique because the table enforces it; a non-unique faker word
            // would make tests fail intermittently rather than meaningfully.
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'category_id' => Category::factory(),
            'desc' => $this->faker->sentence(),
            'unit' => $this->faker->randomElement(['box', 'piece', 'pack']),
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
