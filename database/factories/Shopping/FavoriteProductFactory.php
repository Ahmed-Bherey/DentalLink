<?php

namespace Database\Factories\Shopping;

use App\Models\Shopping\FavoriteProduct;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FavoriteProduct>
 */
class FavoriteProductFactory extends Factory
{
    protected $model = FavoriteProduct::class;

    public function definition(): array
    {
        return [
            'doctor_id' => User::factory()->doctor(),
            'product_id' => Product::factory(),
        ];
    }
}
