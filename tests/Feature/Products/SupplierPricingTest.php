<?php

namespace Tests\Feature\Products;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Order;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STAGE 7.5 — supplier pricing: updating cost, and validation parity.
 *
 *   #6  base_price must be editable, not write-once at creation
 *   #7  create and update must accept the same decimal precision
 *
 * Also re-asserts the Stage 7 rule that neither can reach backwards into an
 * order that has already been placed.
 */
class SupplierPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = User::factory()->supplier()->create();
    }

    private function offer(float $price = 10, ?float $basePrice = 8): Product
    {
        return Product::factory()
            ->ownedBy($this->supplier)
            ->of(ProductMaster::factory()->create())
            ->pricedAt($price)
            ->create(['base_price' => $basePrice, 'quantity' => 50]);
    }

    private function updateAs(User $user, Product $offer, array $body)
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/product/update/{$offer->id}", $body);
    }

    // ============================================ Gap #6 — base_price update

    /** @test */
    public function a_supplier_can_update_the_base_price_of_their_own_offer()
    {
        $offer = $this->offer(10, 8);

        $this->updateAs($this->supplier, $offer, ['base_price' => 9.25])
            ->assertSuccessful()
            ->assertJsonPath('data.base_price', 9.25);

        $this->assertEquals(9.25, $offer->fresh()->base_price);
        $this->assertEquals(10, $offer->fresh()->price, 'The selling price is untouched.');
    }

    /** @test */
    public function base_price_and_selling_price_can_be_updated_together()
    {
        $offer = $this->offer(10, 8);

        $this->updateAs($this->supplier, $offer, ['base_price' => 9, 'price' => 12.50])
            ->assertSuccessful();

        $offer->refresh();

        $this->assertEquals(9, $offer->base_price);
        $this->assertEquals(12.50, $offer->price);
    }

    /** @test */
    public function a_base_price_can_be_cleared()
    {
        $offer = $this->offer(10, 8);

        $this->updateAs($this->supplier, $offer, ['base_price' => null])->assertSuccessful();

        $this->assertNull($offer->fresh()->base_price);
    }

    /**
     * The security rule. ProductPolicy denies a non-owner as NOT FOUND so ids
     * cannot be probed — that behaviour is preserved, not weakened.
     *
     * @test
     */
    public function a_supplier_cannot_update_another_suppliers_base_price()
    {
        $offer = $this->offer(10, 8);
        $intruder = User::factory()->supplier()->create();

        $this->updateAs($intruder, $offer, ['base_price' => 1])->assertStatus(404);

        $this->assertEquals(8, $offer->fresh()->base_price, 'Unchanged.');
    }

    /** @test */
    public function a_doctor_cannot_update_supplier_pricing()
    {
        $offer = $this->offer(10, 8);

        $this->updateAs(User::factory()->doctor()->create(), $offer, ['base_price' => 1, 'price' => 1])
            ->assertStatus(404);

        $offer->refresh();

        $this->assertEquals(8, $offer->base_price);
        $this->assertEquals(10, $offer->price);
    }

    /** @test */
    public function an_unauthenticated_request_cannot_update_pricing()
    {
        $offer = $this->offer(10, 8);

        $this->postJson("/api/product/update/{$offer->id}", ['base_price' => 1])
            ->assertStatus(401);
    }

    /** @test */
    public function a_negative_base_price_is_rejected()
    {
        $offer = $this->offer(10, 8);

        $this->updateAs($this->supplier, $offer, ['base_price' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_price');

        $this->assertEquals(8, $offer->fresh()->base_price);
    }

    /** @test */
    public function a_non_numeric_base_price_is_rejected()
    {
        $offer = $this->offer(10, 8);

        $this->updateAs($this->supplier, $offer, ['base_price' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_price');
    }

    /** @test */
    public function a_base_price_beyond_the_storable_range_is_rejected()
    {
        $offer = $this->offer(10, 8);

        $this->updateAs($this->supplier, $offer, ['base_price' => '99999999999999.99'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_price');
    }

    /**
     * base_price is the supplier's margin. It must never reach a buyer.
     *
     * @test
     */
    public function an_updated_base_price_is_still_hidden_from_a_doctor()
    {
        $offer = $this->offer(10, 8);
        $this->updateAs($this->supplier, $offer, ['base_price' => 9])->assertSuccessful();

        $browsed = $this->actingAs(User::factory()->doctor()->create(), 'sanctum')
            ->getJson('/api/product/all')
            ->assertSuccessful()
            ->json('data.0');

        $this->assertArrayNotHasKey('base_price', $browsed);
    }

    // ==================================== Gap #7 — create/update price parity

    /**
     * @test
     *
     * @dataProvider acceptedPrices
     */
    public function the_same_decimal_price_is_accepted_on_create_and_on_update($price)
    {
        $master = ProductMaster::factory()->create();

        // CREATE
        $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'price' => $price,
                'base_price' => $price,
                'quantity' => 5,
            ])
            ->assertSuccessful();

        $offer = Product::where('user_id', $this->supplier->id)->firstOrFail();
        $this->assertEquals($price, $offer->price);

        // UPDATE — before Stage 7.5 the regex here rejected anything that was
        // not a whole number or a .5 fraction, so 10.25 passed create and
        // failed update.
        $this->updateAs($this->supplier, $offer, ['price' => $price])->assertSuccessful();

        $this->assertEquals($price, $offer->fresh()->price);
    }

    public function acceptedPrices(): array
    {
        return [
            'whole' => [10],
            'quarter' => [10.25],
            'half' => [10.50],
            'two places' => [999.99],
            'zero' => [0],
        ];
    }

    /**
     * @test
     *
     * @dataProvider rejectedPrices
     */
    public function invalid_prices_are_rejected_on_both_create_and_update($price)
    {
        $master = ProductMaster::factory()->create();

        $this->actingAs($this->supplier, 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'price' => $price,
                'quantity' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');

        $offer = $this->offer(10, 8);

        $this->updateAs($this->supplier, $offer, ['price' => $price])
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');

        $this->assertEquals(10, $offer->fresh()->price);
    }

    public function rejectedPrices(): array
    {
        return [
            'negative' => [-1],
            'non numeric' => ['abc'],
            'malformed' => ['10..25'],
            'out of range' => ['99999999999999.99'],
        ];
    }

    // ================================ historical immutability (Stage 7 rule)

    /**
     * The rule the brief restates explicitly: repricing must not reach into an
     * order that has already been placed — for either price column.
     *
     * @test
     */
    public function changing_base_or_selling_price_does_not_alter_an_existing_order()
    {
        $doctor = User::factory()->doctor()->create();
        $offer = $this->offer(10, 7);

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', ['products' => [['id' => $offer->id, 'quantity' => 3]]])
            ->assertSuccessful();

        $order = Order::where('doctor_id', $doctor->id)->orderByDesc('id')->firstOrFail();
        $item = $order->orderItems()->firstOrFail();

        $this->assertEquals(10, $item->unit_price);
        $this->assertEquals(30, $item->line_total);

        // The supplier changes BOTH prices afterwards.
        $this->updateAs($this->supplier, $offer, ['base_price' => 8, 'price' => 12])
            ->assertSuccessful();

        $item->refresh();

        $this->assertEquals(10, $item->unit_price, 'The snapshot is immutable.');
        $this->assertEquals(30, $item->line_total, 'The snapshot is immutable.');

        $line = $this->actingAs($doctor, 'sanctum')
            ->getJson("/api/order/{$order->id}")
            ->assertSuccessful()
            ->json('data.products.0');

        $this->assertEquals(10, $line['unit_price']);
        $this->assertEquals(30, $line['line_total']);
        $this->assertEquals(12, $line['current_product_price'], 'The live price is reported separately.');
    }
}
