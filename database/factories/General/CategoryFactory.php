<?php

namespace Database\Factories\General;

use App\Models\General\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->supplier(),
            'name' => $this->faker->unique()->words(2, true),
            'img' => 'categories/test.png',
            'desc' => $this->faker->sentence(),
        ];
    }
}
