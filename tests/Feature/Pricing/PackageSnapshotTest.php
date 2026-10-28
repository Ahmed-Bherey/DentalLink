<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Financial\OrderItemRefund;
use App\Models\Financial\Package;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H-1 — package purchases and the price snapshot.
 *
 * WHAT IS FIXED: package order lines now carry their IDENTITY snapshot
 * (product_master_id, supplier_id), so they are as traceable as any other line
 * and survive the supplier withdrawing or renaming the offer.
 *
 * WHAT IS DELIBERATELY NOT FIXED: unit_price and line_total remain NULL. A
 * package sells for one agreed price and this domain defines no rule for
 * allocating that price across its components — inventing one would invent a
 * refund value. Until the rule is decided, a per-line return on a package line
 * is REFUSED rather than priced from the product's current price, which is
 * what the old fallback did.
 *
 * The financial total is unaffected either way: a package order is charged
 * `orders.price`, which is fixed at purchase.
 */
class PackageSnapshotTest extends TestCase
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

    private function offer(float $price, int $stock = 100): Product
    {
        return Product::factory()
            ->ownedBy($this->supplier)
            ->of(ProductMaster::factory()->create())
            ->pricedAt($price)
            ->create(['quantity' => $stock]);
    }

    private function packageOf(Product $product, int $quantity, float $packagePrice): Package
    {
        $package = Package::factory()->create([
            'supplier_id' => $this->supplier->id,
            'price' => $packagePrice,
            'active' => true,
        ]);

        DB::table('package_items')->insert([
            'package_id' => $package->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $package->fresh();
    }

    private function buy(Package $package): Order
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/package/buy/{$package->id}", [])
            ->assertSuccessful();

        return Order::where('doctor_id', $this->doctor->id)->orderByDesc('id')->firstOrFail();
    }

    // ------------------------------------------------- identity is recorded

    /** @test */
    public function a_package_order_line_records_its_canonical_product_and_supplier()
    {
        $product = $this->offer(10);
        $order = $this->buy($this->packageOf($product, 10, 90));

        $line = OrderItem::where('order_id', $order->id)->firstOrFail();

        $this->assertSame($product->product_master_id, $line->product_master_id);
        $this->assertSame($this->supplier->id, (int) $line->supplier_id);
        $this->assertSame(10, (int) $line->quantity);
    }

    /** @test */
    public function a_package_order_is_charged_its_agreed_price_not_the_component_prices()
    {
        $product = $this->offer(10);          // components worth 10 x 10 = 100
        $order = $this->buy($this->packageOf($product, 10, 90));   // sold for 90

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertSuccessful();

        $ledger = OrderExpense::where('doctor_id', $this->doctor->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail();

        $this->assertEquals(90, $ledger->total, 'The agreed package price, not 100.');
    }

    /** @test */
    public function repricing_a_component_does_not_change_a_package_order_total()
    {
        $product = $this->offer(10);
        $order = $this->buy($this->packageOf($product, 10, 90));

        $product->update(['price' => 25]);

        $this->assertEquals(90, $order->fresh()->total_order_price);
    }

    // ------------------------------------- per-line refunds are refused, safely

    /**
     * The defect this guard replaces: with no stored unit price the fallback
     * reported the product's CURRENT price, so returning 2 units of a package
     * component bought inside a 90 bundle would have refunded 2 x the live
     * price.
     *
     * @test
     */
    public function a_per_line_return_on_a_package_line_is_refused_rather_than_mispriced()
    {
        $product = $this->offer(10);
        $order = $this->buy($this->packageOf($product, 10, 90));

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertSuccessful();

        $line = OrderItem::where('order_id', $order->id)->firstOrFail();

        // The supplier reprices; under the old fallback this would have driven
        // the refund value.
        $product->update(['price' => 25]);

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/order/items/{$line->id}/return", ['quantity' => 2])
            ->assertSuccessful();

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson("/api/order/{$line->id}/update-status", ['status' => 'confirmed'])
            ->assertStatus(422);

        $this->assertSame(0, OrderItemRefund::count(), 'No mispriced refund was recorded.');

        $ledger = OrderExpense::where('doctor_id', $this->doctor->id)
            ->where('supplier_id', $this->supplier->id)
            ->firstOrFail();

        $this->assertEquals(90, $ledger->total, 'The balance is untouched.');
    }

    /** @test */
    public function a_package_line_still_has_no_individual_price_recorded()
    {
        $order = $this->buy($this->packageOf($this->offer(10), 10, 90));

        $line = OrderItem::where('order_id', $order->id)->firstOrFail();

        // Documented, not accidental: pending a business decision on how a
        // package price is allocated across its components.
        $this->assertNull($line->unit_price);
        $this->assertNull($line->line_total);
    }
}
