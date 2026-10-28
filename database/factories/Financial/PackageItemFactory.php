<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Package;
use App\Models\Financial\PackageItem;
use App\Models\Store\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageItem>
 */
class PackageItemFactory extends Factory
{
    protected $model = PackageItem::class;

    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
        ];
    }
}
