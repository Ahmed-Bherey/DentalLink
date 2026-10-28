<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Cart;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 *
 * NOTE: there is no unique key on (doctor_id, product_id), so duplicate cart
 * lines are representable. => DEFERRED TO DATABASE INTEGRITY STAGE.
 */
class CartFactory extends Factory
{
    protected $model = Cart::class;

    public function definition(): array
    {
        return [
            'doctor_id' => User::factory()->doctor(),
            'product_id' => Product::factory(),
            'quantity' => 1,
        ];
    }

    public function ownedBy(User $doctor): static
    {
        return $this->state(fn () => ['doctor_id' => $doctor->id]);
    }
}
