<?php

namespace Tests\Feature\Cart;

use App\Models\Financial\Cart;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baseline of the CURRENT cart behaviour.
 */
class CartTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_doctor_only_sees_their_own_cart(): void
    {
        $doctor = User::factory()->doctor()->create();
        $other = User::factory()->doctor()->create();

        Cart::factory()->count(2)->ownedBy($doctor)->create();
        Cart::factory()->count(3)->ownedBy($other)->create();

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/cart/index')
            ->assertSuccessful();

        $this->assertSame(2, $response->json('meta.total'));
    }

    /** @test */
    public function a_doctor_can_add_a_product_to_their_cart(): void
    {
        $doctor = User::factory()->doctor()->create();
        $product = Product::factory()->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/cart/store', ['product_id' => $product->id, 'quantity' => 3])
            ->assertSuccessful();

        $this->assertDatabaseHas('carts', [
            'doctor_id' => $doctor->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    /** @test */
    public function cart_ownership_comes_from_the_authenticated_user(): void
    {
        $doctor = User::factory()->doctor()->create();
        $victim = User::factory()->doctor()->create();
        $product = Product::factory()->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/cart/store', [
                'product_id' => $product->id,
                'quantity' => 1,
                'doctor_id' => $victim->id,
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('carts', ['doctor_id' => $doctor->id]);
        $this->assertDatabaseMissing('carts', ['doctor_id' => $victim->id]);
    }

    /** @test */
    public function adding_to_the_cart_requires_an_existing_product(): void
    {
        $doctor = User::factory()->doctor()->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/cart/store', ['product_id' => 99999, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');
    }

    /** @test */
    public function a_doctor_can_remove_their_own_cart_line(): void
    {
        $doctor = User::factory()->doctor()->create();
        $cart = Cart::factory()->ownedBy($doctor)->create();

        $this->actingAs($doctor, 'sanctum')
            ->deleteJson("/api/cart/delete/{$cart->id}")
            ->assertSuccessful();

        $this->assertNull($cart->fresh());
    }

    /**
     * DATA INTEGRITY DEFECT CONFIRMED — DEFERRED TO DATABASE INTEGRITY STAGE.
     *
     * carts has no unique key on (doctor_id, product_id) and CartService::store()
     * performs no duplicate check, so the same product can occupy several lines
     * in one cart.
     *
     * @test
     */
    public function the_same_product_can_currently_be_added_to_a_cart_twice(): void
    {
        $doctor = User::factory()->doctor()->create();
        $product = Product::factory()->create();

        foreach ([1, 1] as $quantity) {
            $this->actingAs($doctor, 'sanctum')
                ->postJson('/api/cart/store', ['product_id' => $product->id, 'quantity' => $quantity])
                ->assertSuccessful();
        }

        $this->assertSame(
            2,
            Cart::where('doctor_id', $doctor->id)->where('product_id', $product->id)->count(),
            'Duplicate cart lines are currently possible.'
        );
    }

    /**
     * BUSINESS RULE GAP CONFIRMED — DEFERRED TO ORDER/INVENTORY STAGE.
     *
     * Cart quantity is not validated against available stock.
     *
     * @test
     */
    public function cart_quantity_is_not_validated_against_stock(): void
    {
        $doctor = User::factory()->doctor()->create();
        $product = Product::factory()->create(['quantity' => 1]);

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/cart/store', ['product_id' => $product->id, 'quantity' => 9999])
            ->assertSuccessful();

        $this->assertDatabaseHas('carts', ['product_id' => $product->id, 'quantity' => 9999]);
    }
}
