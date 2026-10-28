<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRICE SNAPSHOT — now asserting CORRECT behaviour.
 *
 * Stage 2 wrote this file to pin down how the MISSING price snapshot behaved,
 * with the target rule recorded in each docblock so a later stage could invert
 * the assertions. Stage 7 is that stage.
 *
 * THE RULE, in the words of the requirement:
 *   A doctor buys a product at 10 EGP. The supplier later raises the selling
 *   price to 12 EGP. The existing order must remain valued at 10 EGP, including
 *   its outstanding balance.
 */
class OrderPricingBaselineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * FIXED IN STAGE 7 (was: "order_items has no price column").
     *
     * @test
     */
    public function an_order_line_records_the_terms_it_was_bought_on(): void
    {
        foreach (['unit_price', 'line_total', 'product_master_id', 'supplier_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('order_items', $column),
                "order_items.{$column} must exist for an order line to be self-describing."
            );
        }
    }

    /**
     * FIXED IN STAGE 7 — the headline requirement, verbatim.
     *
     * @test
     */
    public function changing_a_product_price_does_not_change_an_existing_order(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->pricedAt(10)->create();

        $order = $this->placeOrder($doctor, $product, 1);

        $this->assertEquals(10, $order->fresh()->total_order_price, 'Ordered at 10.');

        // The supplier raises the current selling price.
        $product->update(['price' => 12]);

        $this->assertEquals(
            10,
            $order->fresh()->total_order_price,
            'The historical order must stay at 10 no matter what the product now costs.'
        );

        // ... and a NEW order placed afterwards uses the new price.
        $laterOrder = $this->placeOrder($doctor, $product->fresh(), 1);

        $this->assertEquals(12, $laterOrder->fresh()->total_order_price);
    }

    /**
     * FIXED IN STAGE 7 (was: "the outstanding balance is accrued at the delivery
     * time price").
     *
     * The gap between placing an order and the supplier marking it delivered
     * could be days. The doctor was billed whatever the price happened to be at
     * the far end of that gap.
     *
     * @test
     */
    public function the_outstanding_balance_is_accrued_at_the_price_the_doctor_ordered_at(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->pricedAt(10)->create();

        $order = $this->placeOrder($doctor, $product, 1);

        // Price changes AFTER the order is placed but BEFORE it is delivered.
        $product->update(['price' => 12]);

        $this->deliver($supplier, $order);

        $ledger = OrderExpense::where('doctor_id', $doctor->id)
            ->where('supplier_id', $supplier->id)
            ->first();

        $this->assertNotNull($ledger);
        $this->assertEquals(10, $ledger->total, 'Billed at the price the doctor agreed to.');
        $this->assertEquals(10, $ledger->remaining);
    }

    /**
     * FIXED IN STAGE 7 (was: "an order total collapses to zero when the product
     * price is null").
     *
     * @test
     */
    public function an_order_keeps_its_value_even_if_the_product_price_is_removed(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->pricedAt(10)->create();

        $order = $this->placeOrder($doctor, $product, 3);

        $this->assertEquals(30, $order->fresh()->total_order_price);

        $product->update(['price' => null]);

        $this->assertEquals(
            30,
            $order->fresh()->total_order_price,
            'A historical order must not become worthless because the product was repriced to null.'
        );
    }

    /**
     * FIXED IN STAGE 7 (was: "a product has only one price column").
     *
     * `price` keeps its meaning — what the buyer pays — and `base_price` is
     * added alongside for the supplier's own cost. Renaming `price` to
     * `selling_price` would have touched every resource, the Excel import and
     * the order totals for no behavioural gain.
     *
     * @test
     */
    public function a_supplier_offer_carries_both_a_base_and_a_selling_price(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'price'), 'price = selling price');
        $this->assertTrue(Schema::hasColumn('products', 'base_price'), 'base_price = supplier cost');

        $offer = Product::factory()->pricedAt(10)->withBasePrice(8)->create();

        $this->assertEquals(10, $offer->price);
        $this->assertEquals(8, $offer->base_price);
        $this->assertSame('2.00', $offer->margin());
    }

    /**
     * Two suppliers, one canonical product, two independent prices.
     *
     * @test
     */
    public function two_suppliers_can_offer_the_same_canonical_product_at_different_prices(): void
    {
        $master = ProductMaster::factory()->named('Dental Composite A')->create();

        $offerX = Product::factory()->of($master)->pricedAt(10)->withBasePrice(8)->create();
        $offerY = Product::factory()->of($master)->pricedAt(11)->withBasePrice(9)->create();

        $this->assertSame($master->id, $offerX->product_master_id);
        $this->assertSame($master->id, $offerY->product_master_id);
        $this->assertNotSame($offerX->user_id, $offerY->user_id);
        $this->assertSame(1, ProductMaster::where('name', 'Dental Composite A')->count());
    }

    /**
     * DOCUMENTED CORRECT BEHAVIOUR — package purchases already snapshot.
     *
     * A package purchase writes the agreed price onto orders.price, and the
     * order total prefers that fixed value. This was already the right pattern
     * and is the one the per-line snapshot now follows.
     *
     * @test
     */
    public function a_package_order_already_holds_a_fixed_price(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->pricedAt(10)->create();

        $order = Order::factory()->placedBy($doctor)->packagePricedAt(500)->create();
        OrderItem::factory()->forOffer($product, 1)->create(['order_id' => $order->id]);

        $product->update(['price' => 999]);

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/order/index-type')
            ->assertSuccessful();

        $this->assertEquals(
            500,
            $response->json('data.0.total_price'),
            'A package order keeps its agreed price even when the product price changes.'
        );
    }

    // ------------------------------------------------------------- helpers

    /**
     * Place an order through the real endpoint, so the snapshot is written by
     * the application rather than by the test.
     */
    private function placeOrder(User $doctor, Product $product, int $quantity): Order
    {
        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $product->id, 'quantity' => $quantity]],
            ])
            ->assertSuccessful();

        return Order::where('doctor_id', $doctor->id)->orderByDesc('id')->first();
    }

    private function deliver(User $supplier, Order $order): void
    {
        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertSuccessful();
    }
}
