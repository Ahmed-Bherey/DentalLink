<?php

namespace Database\Factories\Store;

use App\Models\Catalogue\ProductMaster;
use App\Models\General\Category;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 *
 * NOTE — reflects the CURRENT schema deliberately:
 *
 *  - There is exactly ONE price column (`products.price`, type FLOAT). There is
 *    no base/original price and no separate selling price.
 *    => PRICING ARCHITECTURE DEFECT — DEFERRED TO LATER STAGE.
 *
 *  - `products.user_id` is the owner, and the same table holds BOTH supplier
 *    catalogue rows AND doctor-side inventory rows (the delivery flow clones a
 *    product into the doctor's account). There is no Product Master.
 *    => PRODUCT ARCHITECTURE DEFECT — DEFERRED TO LATER STAGE.
 *
 * This factory does not model either future design.
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->supplier(),
            // STAGE 7: every holding points at a canonical product. A factory
            // that skipped this would produce rows the application can no longer
            // create, hiding the very relationship the tests need to exercise.
            'product_master_id' => ProductMaster::factory(),
            'category_id' => Category::factory(),
            'name' => $this->faker->unique()->words(3, true),
            'img' => 'products/test.png',
            'desc' => $this->faker->sentence(),
            'price' => 100,
            'base_price' => null,
            'quantity' => 50,
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn () => ['user_id' => $owner->id]);
    }

    public function pricedAt(float $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }

    /**
     * A supplier's offer of an EXISTING canonical product.
     *
     * Use this when two suppliers must offer the same product, which is the
     * whole point of the catalogue and cannot be expressed by letting the
     * factory mint a fresh master each time.
     */
    public function of(ProductMaster $master): static
    {
        return $this->state(fn () => [
            'product_master_id' => $master->id,
            'name' => $master->name,
            'category_id' => $master->category_id,
        ]);
    }

    public function withBasePrice(float $basePrice): static
    {
        return $this->state(fn () => ['base_price' => $basePrice]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['quantity' => 0]);
    }
}
