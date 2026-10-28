<?php

namespace Tests\Feature\Authorization;

use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Financial\Package;
use App\Models\Financial\Payment;
use App\Models\Financial\Receipt;
use App\Models\General\Category;
use App\Models\General\City;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint-level authorization matrix.
 *
 * For each governed resource this covers the five cases that matter:
 *   1. unauthenticated
 *   2. authenticated, wrong role
 *   3. authenticated, right role, WRONG owner
 *   4. authenticated, right role, correct owner  (the positive path)
 *   5. id manipulation / ownership field tampering
 *
 * The positive cases are as important as the denials: a policy that denies
 * everyone is not secure, it is broken.
 */
class ResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function doctor(): User
    {
        return User::factory()->doctor()->create();
    }

    private function supplier(): User
    {
        return User::factory()->supplier()->create();
    }

    // ============================================================== RECEIPTS

    /** @test */
    public function receipts_are_private_to_their_owner(): void
    {
        $owner = $this->doctor();
        $attacker = $this->doctor();
        $receipt = Receipt::factory()->ownedBy($owner)->create(['name' => 'Original']);

        // unauthenticated
        $this->getJson("/api/receipt/show/{$receipt->id}")->assertUnauthorized();

        // wrong owner -> hidden entirely
        $this->actingAs($attacker, 'sanctum')
            ->getJson("/api/receipt/show/{$receipt->id}")
            ->assertStatus(404);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/receipt/update/{$receipt->id}", [
                'name' => 'Hijacked', 'price' => 1, 'date' => now()->toDateString(),
            ])
            ->assertStatus(404);

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/receipt/delete/{$receipt->id}")
            ->assertStatus(404);

        $this->assertSame('Original', $receipt->fresh()->name);
        $this->assertNotNull($receipt->fresh());
    }

    /** @test */
    public function a_doctor_can_manage_their_own_receipt(): void
    {
        $owner = $this->doctor();
        $receipt = Receipt::factory()->ownedBy($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/receipt/show/{$receipt->id}")
            ->assertSuccessful();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/receipt/update/{$receipt->id}", [
                'name' => 'Updated', 'price' => 42, 'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        $this->assertSame('Updated', $receipt->fresh()->name);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/receipt/delete/{$receipt->id}")
            ->assertSuccessful();

        $this->assertNull($receipt->fresh());
    }

    // ============================================================ CATEGORIES

    /** @test */
    public function a_category_can_only_be_modified_by_its_creator(): void
    {
        $owner = $this->supplier();
        $attacker = $this->supplier();
        $category = Category::factory()->create(['user_id' => $owner->id, 'name' => 'Original']);

        $this->postJson("/api/category/update/{$category->id}", ['name' => 'x'])->assertUnauthorized();

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/category/update/{$category->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/category/delete/{$category->id}")
            ->assertStatus(404);

        $this->assertSame('Original', $category->fresh()->name);
    }

    /**
     * Categories are shared reference data: everyone may READ one, because
     * products from every supplier are grouped by them.
     *
     * @test
     */
    public function any_authenticated_user_can_read_a_category(): void
    {
        $category = Category::factory()->create(['user_id' => $this->supplier()->id]);

        $this->actingAs($this->doctor(), 'sanctum')
            ->getJson("/api/category/show/{$category->id}")
            ->assertSuccessful();

        $this->actingAs($this->supplier(), 'sanctum')
            ->getJson("/api/category/show/{$category->id}")
            ->assertSuccessful();
    }

    // ================================================================ CITIES

    /** @test */
    public function a_city_can_only_be_modified_by_its_creator(): void
    {
        $owner = $this->supplier();
        $attacker = $this->doctor();
        $city = City::factory()->create(['user_id' => $owner->id, 'name' => 'Original City']);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/city/update/{$city->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        // NOTE: deleting a city still cascade-deletes its users (Stage 5 defect).
        // Authorization narrows who can trigger it; the cascade is unchanged.
        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/city/delete/{$city->id}")
            ->assertStatus(404);

        $this->assertSame('Original City', $city->fresh()->name);
    }

    // ============================================================== PACKAGES

    /** @test */
    public function a_package_is_readable_by_a_doctor_but_only_editable_by_its_supplier(): void
    {
        $owner = $this->supplier();
        $doctor = $this->doctor();
        $package = Package::factory()->ownedBy($owner)->create(['name' => 'Original']);

        // A doctor is being sold this package, so they may read it.
        $this->actingAs($doctor, 'sanctum')
            ->getJson("/api/package/show/{$package->id}")
            ->assertSuccessful();

        // But not modify it.
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/package/update/{$package->id}", [
                'name' => 'Hijacked', 'desc' => 'x', 'price' => 1,
            ])
            ->assertStatus(403);

        $this->assertSame('Original', $package->fresh()->name);
    }

    /** @test */
    public function a_supplier_can_update_their_own_package(): void
    {
        $owner = $this->supplier();
        $package = Package::factory()->ownedBy($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/package/update/{$package->id}", [
                'name' => 'Renamed', 'desc' => 'new', 'price' => 250,
            ])
            ->assertSuccessful();

        $this->assertSame('Renamed', $package->fresh()->name);
    }

    /** @test */
    public function only_a_supplier_can_create_a_package(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->doctor(), 'sanctum')
            ->postJson('/api/package/store', [
                'name' => 'Doctor Package', 'desc' => 'x', 'price' => 100,
                'products' => [['id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);

        $this->assertSame(0, Package::count());
    }

    /** @test */
    public function only_a_doctor_can_buy_a_package(): void
    {
        $package = Package::factory()->ownedBy($this->supplier())->create();

        $this->actingAs($this->supplier(), 'sanctum')
            ->postJson("/api/package/buy/{$package->id}", [])
            ->assertStatus(403);

        $this->assertSame(0, Order::count());
    }

    /** @test */
    public function remaining_products_are_only_visible_to_the_package_owner(): void
    {
        $owner = $this->supplier();
        $attacker = $this->supplier();
        $package = Package::factory()->ownedBy($owner)->create();

        $this->actingAs($attacker, 'sanctum')
            ->getJson("/api/package/remain-products/{$package->id}")
            ->assertStatus(403);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/package/remain-products/{$package->id}")
            ->assertSuccessful();
    }

    // ================================================================ ORDERS

    /** @test */
    public function only_the_fulfilling_supplier_can_drive_an_order_status(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();
        $outsider = $this->supplier();

        $order = Order::factory()->placedBy($doctor)->create(['status' => 'pending']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
        ]);

        $this->postJson("/api/order/update-status/{$order->id}", ['status' => 'preparing'])
            ->assertUnauthorized();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'preparing'])
            ->assertStatus(403);

        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'preparing'])
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);

        // The fulfilling supplier can.
        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'preparing'])
            ->assertSuccessful();

        $this->assertSame('preparing', $order->fresh()->status);
    }

    /** @test */
    public function only_the_ordering_doctor_can_change_a_line_quantity(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();

        $order = Order::factory()->placedBy($doctor)->create();
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
            'quantity' => 3,
        ]);

        // The supplying supplier is a party to the order but may not edit quantity.
        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/order/update-item/{$item->id}", ['quantity' => 99])
            ->assertStatus(403);

        $this->assertSame(3, $item->fresh()->quantity);

        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/order/update-item/{$item->id}", ['quantity' => 5])
            ->assertSuccessful();

        $this->assertSame(5, $item->fresh()->quantity);
    }

    /**
     * The two sides of a return have different rights: the doctor requests,
     * the supplier decides. Neither may do the other's half.
     *
     * @test
     */
    public function the_two_sides_of_a_return_have_different_rights(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();

        $order = Order::factory()->placedBy($doctor)->delivered()->create();
        // ->fulfilled(): fulfilment is now tracked per LINE, not by the order's
        // status alone, and only a delivered line may be returned (C-3). This
        // test is about WHO may act, so the line is made consistent with the
        // delivered order it belongs to.
        $item = OrderItem::factory()->fulfilled()->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
            'quantity' => 5,
        ]);

        // A supplier cannot REQUEST a return on the doctor's behalf.
        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 1])
            ->assertStatus(403);

        // The doctor can.
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/order/items/{$item->id}/return", ['quantity' => 1])
            ->assertSuccessful();

        $this->assertSame('delete_pending', $item->fresh()->status);

        // A doctor cannot DECIDE their own return.
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertSame('delete_pending', $item->fresh()->status);
    }

    // ============================================================== PAYMENTS

    /** @test */
    public function only_a_supplier_can_record_a_payment(): void
    {
        $doctor = $this->doctor();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $doctor->id, 'paid' => 100, 'date' => now()->toDateString(),
            ])
            ->assertStatus(403);

        $this->assertSame(0, Payment::count());
    }

    /** @test */
    public function the_supplier_party_can_amend_and_the_doctor_party_can_decide(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();
        OrderExpense::factory()->between($doctor, $supplier)->withBalance(1000, 0)->create();
        $payment = Payment::factory()->between($doctor, $supplier)->of(100)->create();

        // Doctor cannot amend.
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/payment/update/{$payment->id}", [
                'paid' => 500, 'date' => now()->toDateString(),
            ])
            ->assertStatus(403);

        // Supplier can.
        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/payment/update/{$payment->id}", [
                'paid' => 150, 'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        // Supplier cannot self-approve.
        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertStatus(403);

        // Doctor can decide.
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'rejected'])
            ->assertSuccessful();
    }

    /** @test */
    public function the_pending_payments_inbox_is_doctor_only(): void
    {
        $this->actingAs($this->supplier(), 'sanctum')
            ->getJson('/api/payment/pending')
            ->assertStatus(403);

        $this->actingAs($this->doctor(), 'sanctum')
            ->getJson('/api/payment/pending')
            ->assertSuccessful();
    }

    // ============================================================== DIRECTORY

    /** @test */
    public function the_user_directories_are_role_gated(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();

        // A doctor may not browse other doctors.
        $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/report/doctor/all')
            ->assertStatus(403);

        // A supplier may not browse other suppliers.
        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/report/supplier/all')
            ->assertStatus(403);

        // Each may browse its counterparty.
        $this->actingAs($supplier, 'sanctum')->getJson('/api/report/doctor/all')->assertSuccessful();
        $this->actingAs($doctor, 'sanctum')->getJson('/api/report/supplier/all')->assertSuccessful();
    }

    /** @test */
    public function a_counterparty_detail_lookup_cannot_be_walked_across_roles(): void
    {
        $supplier = $this->supplier();
        $doctor = $this->doctor();
        $otherSupplier = $this->supplier();

        // Reading a doctor is allowed.
        $this->actingAs($supplier, 'sanctum')
            ->getJson("/api/report/doctor/{$doctor->id}/details")
            ->assertSuccessful();

        // Reading another supplier through the same endpoint is hidden.
        $this->actingAs($supplier, 'sanctum')
            ->getJson("/api/report/doctor/{$otherSupplier->id}/details")
            ->assertStatus(404);

        // And a non-existent id is indistinguishable from a forbidden one.
        $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/report/doctor/999999/details')
            ->assertStatus(404);
    }

    // ============================================== ownership field tampering

    /** @test */
    public function ownership_fields_cannot_be_set_through_the_request_payload(): void
    {
        $owner = $this->supplier();
        $victim = $this->supplier();
        $product = Product::factory()->ownedBy($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/product/update/{$product->id}", [
                'name' => 'Renamed',
                'user_id' => $victim->id,       // attempt to hand the product away
            ])
            ->assertSuccessful();

        $this->assertSame($owner->id, $product->fresh()->user_id, 'user_id must not be reassignable.');
        $this->assertSame('Renamed', $product->fresh()->name);
    }

    /** @test */
    public function a_cart_line_cannot_be_reassigned_to_another_doctor(): void
    {
        $owner = $this->doctor();
        $victim = $this->doctor();
        $cart = Cart::factory()->ownedBy($owner)->create(['quantity' => 1]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/cart/update/{$cart->id}", [
                'quantity' => 4,
                'doctor_id' => $victim->id,
            ])
            ->assertSuccessful();

        $this->assertSame($owner->id, $cart->fresh()->doctor_id);
        $this->assertSame(4, $cart->fresh()->quantity);
    }

    /** @test */
    public function a_payment_cannot_be_reassigned_to_another_supplier(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();
        $victim = $this->supplier();
        OrderExpense::factory()->between($doctor, $supplier)->withBalance(1000, 0)->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $doctor->id,
                'supplier_id' => $victim->id,   // ignored: taken from the token
                'paid' => 100,
                'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', ['supplier_id' => $supplier->id]);
        $this->assertDatabaseMissing('payments', ['supplier_id' => $victim->id]);
    }

    // ======================================================= id manipulation

    /**
     * @test
     *
     * @dataProvider tamperableEndpoints
     */
    public function changing_an_id_in_the_url_never_grants_access(string $method, string $template, string $factory, string $ownerRole): void
    {
        $victimOwner = $ownerRole === 'doctor' ? $this->doctor() : $this->supplier();
        $attacker = $ownerRole === 'doctor' ? $this->doctor() : $this->supplier();

        $record = $factory::factory()->create($this->ownerColumnFor($factory, $victimOwner));

        $response = $this->actingAs($attacker, 'sanctum')
            ->json($method, str_replace('{id}', (string) $record->id, $template));

        $this->assertContains(
            $response->status(),
            [403, 404],
            "Tampering with {$template} returned {$response->status()}; expected a denial."
        );
        $this->assertNotNull($record->fresh(), 'The record must survive.');
    }

    public static function tamperableEndpoints(): array
    {
        return [
            'product show' => ['GET', '/api/product/show/{id}', Product::class, 'supplier'],
            'product delete' => ['DELETE', '/api/product/delete/{id}', Product::class, 'supplier'],
            'cart delete' => ['DELETE', '/api/cart/delete/{id}', Cart::class, 'doctor'],
            'receipt show' => ['GET', '/api/receipt/show/{id}', Receipt::class, 'doctor'],
            'receipt delete' => ['DELETE', '/api/receipt/delete/{id}', Receipt::class, 'doctor'],
            'package delete' => ['DELETE', '/api/package/delete/{id}', Package::class, 'supplier'],
            'package toggle' => ['GET', '/api/package/toggle-status/{id}', Package::class, 'supplier'],
        ];
    }

    private function ownerColumnFor(string $model, User $owner): array
    {
        return match ($model) {
            Product::class => ['user_id' => $owner->id],
            Cart::class => ['doctor_id' => $owner->id],
            Receipt::class => ['user_id' => $owner->id],
            Package::class => ['supplier_id' => $owner->id],
            default => [],
        };
    }
}
