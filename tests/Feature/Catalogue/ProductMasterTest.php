<?php

namespace Tests\Feature\Catalogue;

use App\Models\Catalogue\ProductMaster;
use App\Models\General\Category;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * STAGE 7 — the canonical catalogue and supplier pricing.
 *
 * Two rules are under test:
 *
 *   1. Only the Super Admin DEFINES a product.
 *   2. Suppliers and doctors SELECT one and state their own terms for it.
 *
 * Rule 2 is enforced server-side, not by the UI — a supplier posting directly
 * to the API without a product_master_id is refused.
 */
class ProductMasterTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Dental Composite A',
            'category_id' => Category::factory()->create()->id,
            'desc' => 'A canonical product',
            'unit' => 'box',
        ], $overrides);
    }

    // ================================================== Product Master CRUD

    /** @test */
    public function a_super_admin_can_create_a_canonical_product()
    {
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/product-master/store', $this->payload())
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Dental Composite A');

        $this->assertDatabaseHas('product_masters', [
            'name' => 'Dental Composite A',
            'is_active' => 1,
        ]);
    }

    /** @test */
    public function creating_a_canonical_product_records_its_author()
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/product-master/store', $this->payload())
            ->assertSuccessful();

        $this->assertSame($admin->id, (int) ProductMaster::firstOrFail()->created_by);
    }

    /** @test */
    public function a_super_admin_can_update_a_canonical_product()
    {
        $master = ProductMaster::factory()->named('Old Name')->create();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/product-master/update/{$master->id}", $this->payload(['name' => 'New Name']))
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $master->fresh()->name);
    }

    /**
     * The rule the whole catalogue exists to enforce.
     *
     * @test
     */
    public function a_duplicate_canonical_product_name_is_rejected()
    {
        ProductMaster::factory()->named('Dental Composite A')->create();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/product-master/store', $this->payload(['name' => 'Dental Composite A']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, ProductMaster::where('name', 'Dental Composite A')->count());
    }

    /**
     * Enforced by the database too, not only by validation — a concurrent
     * duplicate must not slip through the gap between the check and the insert.
     *
     * @test
     */
    public function the_database_refuses_a_duplicate_canonical_product_name()
    {
        ProductMaster::factory()->named('Unique Item')->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('product_masters')->insert([
            'name' => 'Unique Item',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function updating_a_product_without_changing_its_name_does_not_collide_with_itself()
    {
        $master = ProductMaster::factory()->named('Stable Name')->create();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/product-master/update/{$master->id}", $this->payload(['name' => 'Stable Name']))
            ->assertSuccessful();
    }

    // ====================================================== authorization

    /** @test */
    public function a_supplier_cannot_create_a_canonical_product()
    {
        $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->postJson('/api/product-master/store', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, ProductMaster::count());
    }

    /** @test */
    public function a_doctor_cannot_create_a_canonical_product()
    {
        $this->actingAs(User::factory()->doctor()->create(), 'sanctum')
            ->postJson('/api/product-master/store', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, ProductMaster::count());
    }

    /** @test */
    public function a_supplier_cannot_edit_a_canonical_product()
    {
        $master = ProductMaster::factory()->create();

        $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->postJson("/api/product-master/update/{$master->id}", $this->payload())
            ->assertStatus(403);
    }

    /**
     * Reading is open: selection is impossible without search.
     *
     * @test
     */
    public function suppliers_and_doctors_can_browse_and_search_the_catalogue()
    {
        ProductMaster::factory()->named('Composite Filling')->create();
        ProductMaster::factory()->named('Dental Mirror')->create();

        foreach ([User::factory()->supplier()->create(), User::factory()->doctor()->create()] as $user) {
            $all = $this->actingAs($user, 'sanctum')
                ->getJson('/api/product-master/index')
                ->assertSuccessful()
                ->json('data');

            $this->assertCount(2, $all);

            $filtered = $this->actingAs($user, 'sanctum')
                ->getJson('/api/product-master/index?search=Mirror')
                ->assertSuccessful()
                ->json('data');

            $this->assertCount(1, $filtered);
            $this->assertSame('Dental Mirror', $filtered[0]['name']);
        }
    }

    // ========================================================== archiving

    /** @test */
    public function a_super_admin_can_withdraw_a_product_from_circulation()
    {
        $master = ProductMaster::factory()->create();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/product-master/toggle-active/{$master->id}")
            ->assertSuccessful();

        $this->assertFalse($master->fresh()->is_active);
    }

    /** @test */
    public function an_inactive_product_is_hidden_from_the_picker()
    {
        ProductMaster::factory()->named('Live')->create();
        ProductMaster::factory()->named('Withdrawn')->inactive()->create();

        $listed = $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->getJson('/api/product-master/index')
            ->assertSuccessful()
            ->json('data');

        $this->assertCount(1, $listed);
        $this->assertSame('Live', $listed[0]['name']);
    }

    /** @test */
    public function a_supplier_cannot_list_an_inactive_canonical_product()
    {
        $master = ProductMaster::factory()->inactive()->create();

        $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'price' => 10,
                'quantity' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_master_id');
    }

    // ================================================== supplier pricing

    /** @test */
    public function a_supplier_sets_a_base_and_a_selling_price_on_their_offer()
    {
        Storage::fake('public');

        $supplier = User::factory()->supplier()->create();
        $master = ProductMaster::factory()->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'img' => UploadedFile::fake()->image('p.jpg'),
                'price' => 10,
                'base_price' => 8,
                'quantity' => 20,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('products', [
            'user_id' => $supplier->id,
            'product_master_id' => $master->id,
            'price' => '10.00',
            'base_price' => '8.00',
        ]);
    }

    /** @test */
    public function an_offer_inherits_its_description_from_the_canonical_product()
    {
        $supplier = User::factory()->supplier()->create();
        $master = ProductMaster::factory()->named('Inherited Item')->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'price' => 10,
                'quantity' => 5,
            ])
            ->assertSuccessful();

        $offer = Product::where('user_id', $supplier->id)->firstOrFail();

        $this->assertSame('Inherited Item', $offer->name);
        $this->assertSame($master->category_id, $offer->category_id);
    }

    /** @test */
    public function a_product_offer_must_reference_a_canonical_product()
    {
        $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->postJson('/api/product/store', [
                'name' => 'Invented Out Of Thin Air',
                'price' => 10,
                'quantity' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_master_id');

        $this->assertSame(0, Product::count());
    }

    /** @test */
    public function a_supplier_cannot_list_the_same_canonical_product_twice()
    {
        $supplier = User::factory()->supplier()->create();
        $master = ProductMaster::factory()->create();

        $body = ['product_master_id' => $master->id, 'price' => 10, 'quantity' => 5];

        $this->actingAs($supplier, 'sanctum')->postJson('/api/product/store', $body)->assertSuccessful();
        $this->actingAs($supplier, 'sanctum')->postJson('/api/product/store', $body)->assertStatus(422);

        $this->assertSame(1, Product::where('user_id', $supplier->id)->count());
    }

    /** @test */
    public function the_database_refuses_a_duplicate_holding_of_one_product_by_one_owner()
    {
        $supplier = User::factory()->supplier()->create();
        $master = ProductMaster::factory()->create();

        Product::factory()->ownedBy($supplier)->of($master)->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Product::factory()->ownedBy($supplier)->of($master)->create();
    }

    /** @test */
    public function a_negative_price_is_rejected()
    {
        $master = ProductMaster::factory()->create();

        $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->postJson('/api/product/store', [
                'product_master_id' => $master->id,
                'price' => -1,
                'base_price' => -5,
                'quantity' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'base_price']);
    }

    /**
     * Stage 4 already guarantees this for products generally; asserted here
     * because supplier pricing is the new thing worth pinning.
     *
     * @test
     */
    public function a_supplier_cannot_change_another_suppliers_price()
    {
        $owner = User::factory()->supplier()->create();
        $intruder = User::factory()->supplier()->create();
        $offer = Product::factory()->ownedBy($owner)->pricedAt(10)->create();

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/product/update/{$offer->id}", ['price' => 1, 'quantity' => 5])
            // ProductPolicy denies as NOT FOUND so an id cannot be probed.
            ->assertStatus(404);

        $this->assertEquals(10, $offer->fresh()->price);
    }

    /**
     * Deleting a canonical product is refused outright — order history depends
     * on it. Archiving is the supported route.
     *
     * @test
     */
    public function a_canonical_product_cannot_be_deleted_even_by_a_super_admin()
    {
        $master = ProductMaster::factory()->create();

        $this->assertFalse($this->superAdmin()->can('delete', $master));
    }
}
