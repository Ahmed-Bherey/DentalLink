<?php

namespace Tests\Feature\Products;

use App\Models\Catalogue\ProductMaster;
use App\Models\General\Category;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Baseline of the CURRENT product/catalogue behaviour.
 */
class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_supplier_only_sees_their_own_products_in_the_index(): void
    {
        $supplier = User::factory()->supplier()->create();
        $other = User::factory()->supplier()->create();

        Product::factory()->count(3)->ownedBy($supplier)->create();
        Product::factory()->count(2)->ownedBy($other)->create();

        $response = $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/product/index')
            ->assertSuccessful();

        $this->assertCount(3, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    /** @test */
    public function a_supplier_can_create_a_product(): void
    {
        Storage::fake('public');

        $supplier = User::factory()->supplier()->create();

        // STAGE 7: a supplier SELECTS an existing canonical product rather than
        // defining a free-text one, and states their own commercial terms.
        $master = ProductMaster::factory()->named('Dental Mirror')->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'img' => UploadedFile::fake()->image('mirror.jpg'),
                'desc' => 'A mirror',
                'price' => 100,
                'base_price' => 80,
                'quantity' => 10,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('products', [
            'product_master_id' => $master->id,
            'user_id' => $supplier->id,
            'price' => '100.00',
            'base_price' => '80.00',
        ]);
    }

    /** @test */
    public function product_creation_assigns_ownership_from_the_authenticated_user(): void
    {
        Storage::fake('public');

        $supplier = User::factory()->supplier()->create();
        $victim = User::factory()->supplier()->create();
        $master = ProductMaster::factory()->create();

        // Even if the client supplies user_id, ownership must come from the token.
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'name' => 'Owned Product',
                'img' => UploadedFile::fake()->image('p.jpg'),
                'desc' => 'desc is mandatory in practice, see the defect test below',
                'price' => 50,
                'quantity' => 5,
                'user_id' => $victim->id,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('products', ['name' => 'Owned Product', 'user_id' => $supplier->id]);
        $this->assertDatabaseMissing('products', ['name' => 'Owned Product', 'user_id' => $victim->id]);
    }

    /** @test */
    public function product_creation_validates_required_fields(): void
    {
        $supplier = User::factory()->supplier()->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/product/store', [])
            ->assertStatus(422)
            // STAGE 7: `product_master_id` is the new mandatory field - it is
            // what makes selection, rather than invention, the only way in.
            // `name`, `category_id` and `img` are now inherited from the
            // canonical product when omitted, so they are optional.
            ->assertJsonValidationErrors(['product_master_id', 'price', 'quantity']);
    }

    /** @test */
    public function a_supplier_can_read_their_own_product(): void
    {
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->create();

        $this->actingAs($supplier, 'sanctum')
            ->getJson("/api/product/show/{$product->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $product->id);
    }

    /** @test */
    public function a_supplier_can_search_only_within_their_own_catalogue(): void
    {
        $supplier = User::factory()->supplier()->create();
        $other = User::factory()->supplier()->create();

        Product::factory()->ownedBy($supplier)->create(['name' => 'Scaler Tip Alpha']);
        Product::factory()->ownedBy($other)->create(['name' => 'Scaler Tip Beta']);

        $response = $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/product/search?search=Scaler')
            ->assertSuccessful();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Scaler Tip Alpha', $response->json('data.0.name'));
    }

    /** @test */
    public function a_doctor_can_browse_all_supplier_products(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplierA = User::factory()->supplier()->create();
        $supplierB = User::factory()->supplier()->create();

        Product::factory()->ownedBy($supplierA)->create();
        Product::factory()->ownedBy($supplierB)->create();

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/product/all')
            ->assertSuccessful();

        $this->assertSame(2, $response->json('meta.total'));
    }

    /** @test */
    public function a_supplier_cannot_use_the_doctor_shopping_endpoint(): void
    {
        $supplier = User::factory()->supplier()->create();

        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/product/all')
            ->assertStatus(403);
    }

    /**
     * FIXED IN STAGE 7 (was: "the API truncates fractional prices to integers").
     *
     * InventoryResource cast price with (int), so a supplier could store 99.50
     * and the API reported 99 — the same truncation Stage 6 removed from the
     * payment endpoints, on the column this stage is about.
     *
     * @test
     */
    public function the_api_reports_fractional_prices_exactly(): void
    {
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->pricedAt(99.5)->create();

        $response = $this->actingAs($supplier, 'sanctum')
            ->getJson("/api/product/show/{$product->id}")
            ->assertSuccessful();

        $this->assertEquals(99.5, $product->fresh()->price, 'The database keeps the fraction.');
        $this->assertSame(99.5, $response->json('data.price'), 'And so does the API.');
    }

    /**
     * A supplier's COST reveals their margin, so it must reach the owner only.
     *
     * @test
     */
    public function the_base_price_is_visible_to_the_owner_and_hidden_from_a_doctor(): void
    {
        $supplier = User::factory()->supplier()->create();
        $doctor = User::factory()->doctor()->create();
        $product = Product::factory()->ownedBy($supplier)->pricedAt(10)->withBasePrice(8)->create();

        $own = $this->actingAs($supplier, 'sanctum')
            ->getJson("/api/product/show/{$product->id}")
            ->assertSuccessful()
            ->json('data');

        $this->assertEquals(8, $own['base_price']);

        $browsed = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/product/all')
            ->assertSuccessful()
            ->json('data.0');

        $this->assertArrayNotHasKey('base_price', $browsed, 'A doctor must not see the supplier cost.');
        $this->assertEquals(10, $browsed['price']);
    }

    /**
     * FIXED IN STAGE 7 (was: "identical products from different suppliers are
     * unrelated rows").
     *
     * Two suppliers stocking the same item are now two OFFERS of one canonical
     * product, which is what makes the catalogue searchable and comparable.
     *
     * @test
     */
    public function identical_products_from_different_suppliers_share_one_canonical_product(): void
    {
        $supplierA = User::factory()->supplier()->create();
        $supplierB = User::factory()->supplier()->create();

        $master = ProductMaster::factory()->named('Dental Mirror')->create();

        $a = Product::factory()->ownedBy($supplierA)->of($master)->pricedAt(10)->create();
        $b = Product::factory()->ownedBy($supplierB)->of($master)->pricedAt(12)->create();

        $this->assertNotSame($a->id, $b->id, 'Each supplier keeps their own offer.');
        $this->assertSame($master->id, $a->product_master_id);
        $this->assertSame($master->id, $b->product_master_id);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $master->products()->pluck('id')->all(),
            'Both offers hang off the one canonical product.'
        );
    }

    /**
     * PARTIALLY FIXED IN STAGE 7 (was: "a doctor can currently create a product").
     *
     * A doctor may still create a `products` row — that row is their INVENTORY,
     * and Stage 4 documented (D-1) that doctors legitimately hold stock. What
     * they can no longer do is invent a canonical product: the row must point at
     * an existing catalogue entry, asserted in the ProductMaster tests.
     *
     * @test
     */
    public function a_doctor_holds_inventory_against_an_existing_canonical_product(): void
    {
        Storage::fake('public');

        $doctor = User::factory()->doctor()->create();
        $master = ProductMaster::factory()->named('Doctor Created Product')->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'name' => 'Doctor Created Product',
                'img' => UploadedFile::fake()->image('x.jpg'),
                'desc' => 'created by a doctor',
                'price' => 10,
                'quantity' => 1,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('products', [
            'name' => 'Doctor Created Product',
            'user_id' => $doctor->id,
        ]);
    }

    /**
     * APPLICATION DEFECT CONFIRMED — DEFERRED TO ERROR-HANDLING / ORDER-FLOW STAGE.
     *
     * Discovered while writing this baseline.
     *
     * InventoryRequest declares `desc` as `nullable`, so a valid request may omit
     * it. InventoryService::create() then reads $data['desc'] unconditionally,
     * which raises "Undefined array key: desc". The controller's blanket
     * `catch (Exception)` swallows it and returns a generic 422, so the client is
     * told the request is invalid without being told which field, and nothing is
     * logged.
     *
     * Effect: a product cannot be created without a description, despite the
     * validation rules saying otherwise.
     *
     * This test asserts the CURRENT (incorrect) behaviour so the fix is a
     * deliberate, visible change.
     *
     * @test
     */
    public function creating_a_product_without_a_description_currently_fails(): void
    {
        Storage::fake('public');

        $supplier = User::factory()->supplier()->create();
        $category = Category::factory()->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/product/store', [
                'name' => 'No Description Product',
                'category_id' => $category->id,
                'img' => UploadedFile::fake()->image('nodesc.jpg'),
                'price' => 10,
                'quantity' => 1,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('products', ['name' => 'No Description Product']);
    }
}
