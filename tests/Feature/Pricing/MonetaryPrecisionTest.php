<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Package;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every endpoint that reports money must report it exactly.
 *
 * Stage 6 removed `(int)` truncation from the payment endpoints and Stage 7.5
 * removed it from order lines. These four resources were the remainder: a cart
 * line of 10.50 was reported as 10, which misstates what the doctor is about
 * to be charged.
 */
class MonetaryPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = User::factory()->doctor()->create();
        $this->supplier = User::factory()->supplier()->create();
    }

    private function offer(float $price): Product
    {
        return Product::factory()
            ->ownedBy($this->supplier)
            ->of(ProductMaster::factory()->create())
            ->pricedAt($price)
            ->create(['quantity' => 100]);
    }

    /** @test */
    public function a_cart_line_reports_exact_money()
    {
        $offer = $this->offer(10.50);

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/cart/store', ['product_id' => $offer->id, 'quantity' => 3])
            ->assertSuccessful();

        $line = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/cart/index')
            ->assertSuccessful()
            ->json('data.0');

        $this->assertEquals(10.50, $line['price'], 'Was truncated to 10.');
        $this->assertEquals(31.50, $line['total_price'], '3 x 10.50.');
    }

    /** @test */
    public function a_favourite_reports_an_exact_price()
    {
        $offer = $this->offer(125.75);

        $this->actingAs($this->doctor, 'sanctum')
            ->getJson("/api/favorite-product/add/{$offer->id}")
            ->assertSuccessful();

        $row = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/favorite-product/index')
            ->assertSuccessful()
            ->json('data.0');

        $this->assertEquals(125.75, $row['price']);
    }

    /** @test */
    public function a_package_reports_an_exact_price_and_exact_component_prices()
    {
        $offer = $this->offer(10.25);

        $package = Package::factory()->create([
            'supplier_id' => $this->supplier->id,
            'price' => 99.99,
            'active' => true,
        ]);

        DB::table('package_items')->insert([
            'package_id' => $package->id,
            'product_id' => $offer->id,
            'quantity' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = $this->actingAs($this->supplier, 'sanctum')
            ->getJson('/api/package/index')
            ->assertSuccessful()
            ->json('data.0');

        $this->assertEquals(99.99, $row['total_price'], 'Was truncated to 99.');
        $this->assertEquals(10.25, $row['products'][0]['price'], 'Was truncated to 10.');
    }

    /** @test */
    public function a_browsed_product_reports_an_exact_price()
    {
        $this->offer(10.25);

        $row = $this->actingAs($this->doctor, 'sanctum')
            ->getJson('/api/product/all')
            ->assertSuccessful()
            ->json('data.0');

        $this->assertEquals(10.25, $row['price']);
    }
}
