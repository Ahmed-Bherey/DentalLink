<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 *
 * `orders.price` is only populated for package purchases; a normal order leaves
 * it null and the total is recomputed from the CURRENT product price at read
 * time. That is the known price-snapshot defect — see OrderPricingBaselineTest.
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'doctor_id' => User::factory()->doctor(),
            'notes' => $this->faker->sentence(),
            'status' => 'pending',
            'payment_method' => 'مدفوعات',
            'price' => null,
        ];
    }

    public function placedBy(User $doctor): static
    {
        return $this->state(fn () => ['doctor_id' => $doctor->id]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function delivered(): static
    {
        return $this->withStatus('delivered');
    }

    /** A package purchase carries a fixed price on the order itself. */
    public function packagePricedAt(float $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }
}
