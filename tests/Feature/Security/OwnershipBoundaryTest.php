<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\Store\Product;
use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Models\Financial\OrderItem;
use App\Models\Financial\Package;
use App\Models\Financial\Payment;
use App\Models\General\Department;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Stage 1 IDOR/BOLA regression tests.
 *
 * Each test proves that the owner of a record can act on it and a non-owner
 * cannot. These exercise the interim ownership guards added in Stage 1; they
 * will be re-pointed at the real policy layer in Stage 4 without changing the
 * assertions, which is the point — the security contract stays the same.
 *
 * SAFETY: these tests write to a database, so they refuse to run unless a
 * DEDICATED test database is configured. The project currently has no test
 * database (phpunit.xml has DB_CONNECTION/DB_DATABASE commented out), so they
 * skip today and become live in Stage 2 when the test harness lands. They are
 * committed now so the security contract is captured while it is fresh.
 */
class OwnershipBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The skip guard must run BEFORE setUpTraits(), because DatabaseTransactions
     * opens a connection there. At this point the application is booted, so
     * config() is available.
     */
    protected function setUpTraits()
    {
        $database = (string) config('database.connections.' . config('database.default') . '.database');

        if (!str_ends_with($database, '_test')) {
            $this->markTestSkipped(
                'No dedicated test database configured. Point DB_DATABASE at a *_test database in phpunit.xml (Stage 2).'
            );
        }

        return parent::setUpTraits();
    }

    /** @test */
    public function a_supplier_cannot_update_another_suppliers_product(): void
    {
        [$owner, $attacker] = [$this->supplier(), $this->supplier()];
        $product = $this->productFor($owner, ['price' => 100]);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/product/update/{$product->id}", ['price' => 1])
            ->assertStatus(404);

        $this->assertEquals(100, $product->fresh()->price, 'Price must be unchanged by a non-owner.');
    }

    /** @test */
    public function a_supplier_can_update_their_own_product(): void
    {
        $owner = $this->supplier();
        $product = $this->productFor($owner, ['price' => 100]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/product/update/{$product->id}", ['price' => 150])
            ->assertSuccessful();

        $this->assertEquals(150, $product->fresh()->price);
    }

    /** @test */
    public function a_supplier_cannot_delete_another_suppliers_product(): void
    {
        [$owner, $attacker] = [$this->supplier(), $this->supplier()];
        $product = $this->productFor($owner);

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/product/delete/{$product->id}")
            ->assertStatus(404);

        $this->assertNotNull($product->fresh(), 'Product must survive a non-owner delete.');
    }

    /** @test */
    public function bulk_delete_refuses_a_batch_containing_another_suppliers_product(): void
    {
        [$owner, $attacker] = [$this->supplier(), $this->supplier()];
        $mine = $this->productFor($attacker);
        $theirs = $this->productFor($owner);

        $this->actingAs($attacker, 'sanctum')
            ->postJson('/api/product/multi-delete', ['ids' => [$mine->id, $theirs->id]])
            ->assertStatus(403);

        $this->assertNotNull($theirs->fresh(), 'Another supplier\'s product must survive.');
        $this->assertNotNull($mine->fresh(), 'The whole batch must be refused, not partially applied.');
    }

    /** @test */
    public function a_supplier_cannot_read_another_suppliers_product(): void
    {
        [$owner, $attacker] = [$this->supplier(), $this->supplier()];
        $product = $this->productFor($owner);

        $this->actingAs($attacker, 'sanctum')
            ->getJson("/api/product/show/{$product->id}")
            ->assertStatus(404);
    }

    /** @test */
    public function a_doctor_cannot_modify_another_doctors_cart_line(): void
    {
        [$owner, $attacker] = [$this->doctor(), $this->doctor()];
        $product = $this->productFor($this->supplier());
        $cart = Cart::create(['doctor_id' => $owner->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/cart/update/{$cart->id}", ['quantity' => 99])
            ->assertStatus(404);

        $this->assertEquals(1, $cart->fresh()->quantity);
    }

    /** @test */
    public function a_doctor_cannot_delete_another_doctors_cart_line(): void
    {
        [$owner, $attacker] = [$this->doctor(), $this->doctor()];
        $product = $this->productFor($this->supplier());
        $cart = Cart::create(['doctor_id' => $owner->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/cart/delete/{$cart->id}")
            ->assertStatus(404);

        $this->assertNotNull($cart->fresh());
    }

    /** @test */
    public function a_supplier_cannot_delete_another_suppliers_package(): void
    {
        [$owner, $attacker] = [$this->supplier(), $this->supplier()];
        $package = Package::create(['supplier_id' => $owner->id, 'name' => 'p', 'price' => 10]);

        $this->actingAs($attacker, 'sanctum')
            ->deleteJson("/api/package/delete/{$package->id}")
            ->assertStatus(403);

        $this->assertNotNull($package->fresh());
    }

    /** @test */
    public function a_supplier_cannot_toggle_another_suppliers_package(): void
    {
        [$owner, $attacker] = [$this->supplier(), $this->supplier()];
        $package = Package::create(['supplier_id' => $owner->id, 'name' => 'p', 'price' => 10, 'active' => true]);

        $this->actingAs($attacker, 'sanctum')
            ->getJson("/api/package/toggle-status/{$package->id}")
            ->assertStatus(403);

        $this->assertTrue((bool) $package->fresh()->active);
    }

    /** @test */
    public function a_third_party_cannot_confirm_someone_elses_payment(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();
        $attacker = $this->doctor();

        $payment = Payment::create([
            'doctor_id'   => $doctor->id,
            'supplier_id' => $supplier->id,
            'amount'      => 50,
            'status'      => 'pending',
        ]);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    /** @test */
    public function the_supplier_party_cannot_confirm_their_own_payment(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();

        $payment = Payment::create([
            'doctor_id'   => $doctor->id,
            'supplier_id' => $supplier->id,
            'amount'      => 50,
            'status'      => 'pending',
        ]);

        // Confirmation is the doctor's decision; the supplier must not self-approve.
        $this->actingAs($supplier, 'sanctum')
            ->postJson("/api/payment/confirm/{$payment->id}", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    /** @test */
    public function a_third_party_cannot_request_deletion_of_someone_elses_payment(): void
    {
        $payment = Payment::create([
            'doctor_id'   => $this->doctor()->id,
            'supplier_id' => $this->supplier()->id,
            'amount'      => 50,
            'status'      => 'confirmed',
        ]);

        $this->actingAs($this->supplier(), 'sanctum')
            ->getJson("/api/payment/delete/{$payment->id}")
            ->assertStatus(403);

        $this->assertSame('confirmed', $payment->fresh()->status);
    }

    /** @test */
    public function a_user_who_owns_no_line_in_an_order_cannot_change_its_status(): void
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();
        $attacker = $this->supplier();

        $order = Order::create(['doctor_id' => $doctor->id, 'status' => 'pending']);
        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $this->productFor($supplier)->id,
            'quantity'   => 1,
        ]);

        $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function a_doctor_cannot_mark_their_own_order_delivered(): void
    {
        $doctor = $this->doctor();
        $order = Order::create(['doctor_id' => $doctor->id, 'status' => 'pending']);
        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $this->productFor($this->supplier())->id,
            'quantity'   => 1,
        ]);

        // Self-crediting inventory and fabricating a receivable was the original exploit.
        $this->actingAs($doctor, 'sanctum')
            ->postJson("/api/order/update-status/{$order->id}", ['status' => 'delivered'])
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function a_doctor_cannot_flag_another_doctors_order_for_deletion(): void
    {
        $order = Order::create(['doctor_id' => $this->doctor()->id, 'status' => 'pending']);

        $this->actingAs($this->doctor(), 'sanctum')
            ->deleteJson("/api/order/delete/{$order->id}")
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    /** @test */
    public function a_supplier_who_does_not_own_the_product_cannot_approve_a_return(): void
    {
        $order = Order::create(['doctor_id' => $this->doctor()->id, 'status' => 'delivered']);
        $item = OrderItem::create([
            'order_id'          => $order->id,
            'product_id'        => $this->productFor($this->supplier())->id,
            'quantity'          => 5,
            'status'            => 'delete_pending',
            'returned_quantity' => 2,
        ]);

        $this->actingAs($this->supplier(), 'sanctum')
            ->postJson("/api/order/{$item->id}/update-status", ['status' => 'confirmed'])
            ->assertStatus(403);

        $this->assertSame('delete_pending', $item->fresh()->status);
    }

    /** @test */
    public function a_user_cannot_read_an_arbitrary_user_record_through_the_doctor_report(): void
    {
        $targetSupplier = $this->supplier();

        // The endpoint must only ever resolve doctors, never other suppliers.
        $this->actingAs($this->supplier(), 'sanctum')
            ->getJson("/api/report/doctor/{$targetSupplier->id}/details")
            ->assertStatus(422);
    }

    /** @test */
    public function registration_rejects_a_privileged_department(): void
    {
        $privileged = Department::create(['name' => 'Admin', 'code' => 'admin']);

        $this->postJson('/api/register', [
            'name'          => 'Attacker',
            'email'         => 'attacker' . uniqid() . '@example.test',
            'phone'         => '0100' . random_int(1000000, 9999999),
            'address'       => 'somewhere',
            'city_id'       => $this->city()->id,
            'department_id' => $privileged->id,
            'password'      => 'secret123',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('department_id');

        $this->assertDatabaseMissing('users', ['department_id' => $privileged->id]);
    }

    /** @test */
    public function the_public_department_list_does_not_expose_privileged_codes(): void
    {
        Department::create(['name' => 'Admin', 'code' => 'admin']);

        $response = $this->getJson('/api/department/index')->assertSuccessful();

        $codes = collect($response->json('data'))->pluck('role')->all();
        $this->assertNotContains('admin', $codes);
    }

    // ---------------------------------------------------------------- helpers

    private function supplier(): User
    {
        return $this->userWithDepartment('supplier');
    }

    private function doctor(): User
    {
        return $this->userWithDepartment('doctor');
    }

    private function userWithDepartment(string $code): User
    {
        $department = Department::firstOrCreate(['code' => $code], ['name' => ucfirst($code)]);

        return User::create([
            'name'          => $code . '-' . uniqid(),
            'email'         => $code . uniqid() . '@example.test',
            'phone'         => (string) random_int(1000000000, 1999999999),
            'password'      => bcrypt('secret123'),
            'department_id' => $department->id,
        ]);
    }

    private function productFor(User $owner, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id'  => $owner->id,
            'name'     => 'product-' . uniqid(),
            'price'    => 100,
            'quantity' => 10,
        ], $attributes));
    }

    private function city()
    {
        return \App\Models\General\City::firstOrCreate(['name' => 'TestCity']);
    }
}
