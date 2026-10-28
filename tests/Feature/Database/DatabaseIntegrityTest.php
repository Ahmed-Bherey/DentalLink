<?php

namespace Tests\Feature\Database;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Financial\Payment;
use App\Models\Financial\Receipt;
use App\Models\General\Category;
use App\Models\General\City;
use App\Models\Role;
use App\Models\Shopping\FavoriteProduct;
use App\Models\Store\Product;
use App\Models\User;
use App\Support\RoleCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * STAGE 5 — database-level integrity invariants.
 *
 * These assert what the DATABASE guarantees, deliberately bypassing the
 * application layer. A rule enforced only in PHP is one `php artisan tinker`,
 * one console command or one hand-written query away from being ignored, and
 * several of the rules below previously existed only in PHP or not at all.
 *
 * Each test states the invariant, not the implementation: no test asserts a
 * constraint name or an index shape, only the behaviour a constraint produces.
 */
class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run a raw statement and return the database's complaint, or fail.
     *
     * Going through the query builder rather than Eloquent keeps the assertion
     * about the schema instead of about model events or validation.
     */
    private function expectDatabaseToReject(callable $operation, string $because): QueryException
    {
        try {
            $operation();
        } catch (QueryException $e) {
            // SQLSTATE 23000 is "integrity constraint violation" and covers
            // foreign keys, unique indexes and NOT NULL alike. Asserting on it
            // rather than merely catching QueryException proves the rejection
            // came from the schema — a syntax error or a missing table would
            // also throw QueryException and would otherwise pass silently.
            $this->assertSame(
                '23000',
                (string) $e->getCode(),
                "Expected an integrity constraint violation for: {$because}. Got: {$e->getMessage()}"
            );

            return $e;
        }

        $this->fail("The database accepted an operation it should have rejected: {$because}");
    }

    private function doctor(): User
    {
        return User::factory()->doctor()->create();
    }

    private function supplier(): User
    {
        return User::factory()->supplier()->create();
    }

    // =====================================================================
    // Referential integrity — invalid references must be impossible
    // =====================================================================

    /** @test */
    public function an_order_item_cannot_reference_a_product_that_does_not_exist()
    {
        $order = Order::factory()->for($this->doctor(), 'doctor')->create();

        $this->expectDatabaseToReject(
            fn () => DB::table('order_items')->insert([
                'order_id' => $order->id,
                'product_id' => 999999,
                'quantity' => 1,
            ]),
            'an order line pointing at a non-existent product'
        );
    }

    /** @test */
    public function a_payment_cannot_reference_a_user_that_does_not_exist()
    {
        $this->expectDatabaseToReject(
            fn () => DB::table('payments')->insert([
                'doctor_id' => 999999,
                'supplier_id' => $this->supplier()->id,
                'amount' => 100,
            ]),
            'a payment attributed to a non-existent doctor'
        );
    }

    /** @test */
    public function a_user_cannot_be_given_a_role_that_does_not_exist()
    {
        $user = $this->doctor();

        $this->expectDatabaseToReject(
            fn () => DB::table('users')->where('id', $user->id)->update(['role_id' => 999999]),
            'a user pointing at a non-existent role'
        );
    }

    // =====================================================================
    // Mandatory ownership — a row must always be attributable to someone
    // =====================================================================

    /**
     * Stage 4's ChecksOwnership::owns() treats a NULL owner as "owned by
     * nobody", so an ownerless row is invisible and undeletable through the API.
     *
     * @test
     *
     * @dataProvider ownershipColumns
     */
    public function ownership_and_relationship_columns_reject_null($table, $column, $row)
    {
        $this->expectDatabaseToReject(
            fn () => DB::table($table)->insert(array_merge($row(), [$column => null])),
            "{$table}.{$column} set to NULL leaves the row unattributable"
        );
    }

    public function ownershipColumns(): array
    {
        // Closures because the data has to be built after the database is
        // refreshed, which has not happened when the provider runs.
        return [
            // Stage 7 made product_master_id mandatory too, so it must be
            // supplied here or the row would fail for the wrong reason and
            // stop testing what this case is about.
            'products.user_id' => ['products', 'user_id', fn () => [
                'name' => 'x', 'price' => 1, 'quantity' => 1,
                'product_master_id' => ProductMaster::factory()->create()->id,
            ]],
            'orders.doctor_id' => ['orders', 'doctor_id', fn () => ['status' => 'pending']],
            'receipts.user_id' => ['receipts', 'user_id', fn () => ['name' => 'x', 'value' => 1]],
            'payments.doctor_id' => ['payments', 'doctor_id', fn () => ['supplier_id' => User::factory()->supplier()->create()->id, 'amount' => 1]],
            'payments.supplier_id' => ['payments', 'supplier_id', fn () => ['doctor_id' => User::factory()->doctor()->create()->id, 'amount' => 1]],
            'order_items.order_id' => ['order_items', 'order_id', fn () => ['product_id' => Product::factory()->create()->id, 'quantity' => 1]],
            'carts.doctor_id' => ['carts', 'doctor_id', fn () => ['product_id' => Product::factory()->create()->id, 'quantity' => 1]],
        ];
    }

    /**
     * The counterpart: these MUST stay nullable. Stage 3 defines "no role" as a
     * valid access-free state, and the SET NULL rules added in Stage 5 depend on
     * the target column accepting NULL.
     *
     * @test
     */
    public function columns_that_are_legitimately_optional_remain_nullable()
    {
        $user = $this->doctor();

        DB::table('users')->where('id', $user->id)->update([
            'role_id' => null,
            'department_id' => null,
            'city_id' => null,
        ]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'role_id' => null]);

        // A role-less user must still load and must resolve to no access.
        $this->assertNull($user->fresh()->roleCode());
    }

    // =====================================================================
    // Deletion must not erase somebody else's history
    // =====================================================================

    /**
     * The headline defect this stage exists to fix. A supplier could delete
     * their own product and the cascade would remove line items from doctors'
     * completed orders — changing what those orders contained and totalled,
     * with no notification to the buyer and no way back.
     *
     * @test
     */
    public function a_product_that_appears_in_an_order_cannot_be_deleted()
    {
        $product = Product::factory()->for($this->supplier())->create();
        $order = Order::factory()->for($this->doctor(), 'doctor')->create();

        OrderItem::factory()->for($order)->for($product)->create();

        $this->expectDatabaseToReject(
            fn () => DB::table('products')->where('id', $product->id)->delete(),
            'deleting a product that is referenced by order history'
        );

        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $product->id]);
    }

    /** @test */
    public function a_user_with_orders_cannot_be_deleted()
    {
        $doctor = $this->doctor();
        Order::factory()->for($doctor, 'doctor')->create();

        $this->expectDatabaseToReject(
            fn () => DB::table('users')->where('id', $doctor->id)->delete(),
            'deleting a doctor who has order history'
        );
    }

    /** @test */
    public function a_user_with_payments_cannot_be_deleted()
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();

        Payment::factory()->create([
            'doctor_id' => $doctor->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->expectDatabaseToReject(
            fn () => DB::table('users')->where('id', $supplier->id)->delete(),
            'deleting a supplier who is party to a payment'
        );
    }

    /** @test */
    public function a_user_with_receipts_cannot_be_deleted()
    {
        $user = $this->supplier();
        Receipt::factory()->for($user)->create();

        $this->expectDatabaseToReject(
            fn () => DB::table('users')->where('id', $user->id)->delete(),
            'deleting a user who has financial receipts'
        );
    }

    /** @test */
    public function a_supplier_with_products_cannot_be_deleted()
    {
        $supplier = $this->supplier();
        Product::factory()->for($supplier)->create();

        $this->expectDatabaseToReject(
            fn () => DB::table('users')->where('id', $supplier->id)->delete(),
            'deleting a supplier who still owns inventory'
        );
    }

    /**
     * CategoryService already refuses this in PHP. The database used to do the
     * exact opposite — it deleted the products.
     *
     * @test
     */
    public function a_category_that_still_has_products_cannot_be_deleted()
    {
        $category = Category::factory()->create();
        Product::factory()->for($this->supplier())->create(['category_id' => $category->id]);

        $this->expectDatabaseToReject(
            fn () => DB::table('categories')->where('id', $category->id)->delete(),
            'deleting a category whose products would be destroyed with it'
        );
    }

    /** @test */
    public function a_role_that_is_still_assigned_cannot_be_deleted()
    {
        $doctor = $this->doctor();

        $this->expectDatabaseToReject(
            fn () => DB::table('roles')->where('id', $doctor->role_id)->delete(),
            'deleting a role that users still depend on for access'
        );
    }

    // =====================================================================
    // Reference data degrades; it does not take its dependants with it
    // =====================================================================

    /**
     * Deleting a city used to CASCADE INTO USERS and delete every account
     * registered there. Cities are deletable through CityController, so this
     * was reachable in production.
     *
     * @test
     */
    public function deleting_a_city_clears_the_reference_but_keeps_the_users()
    {
        $city = City::factory()->create();
        $user = User::factory()->doctor()->create(['city_id' => $city->id]);

        DB::table('cities')->where('id', $city->id)->delete();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'city_id' => null]);
    }

    /**
     * Categories are shared reference data; user_id records only who created the
     * row. Losing the creator must not remove a category other suppliers use.
     *
     * @test
     */
    public function deleting_a_categorys_creator_orphans_the_category_rather_than_deleting_it()
    {
        $creator = $this->supplier();
        $category = Category::factory()->create(['user_id' => $creator->id]);

        // Detach everything that would independently block the user delete, so
        // this test measures the category rule and nothing else.
        DB::table('products')->where('user_id', $creator->id)->delete();
        DB::table('users')->where('id', $creator->id)->delete();

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'user_id' => null]);
    }

    /**
     * An ownerless category must not become a free-for-all. ChecksOwnership
     * returns false for a NULL owner, so it degrades to read-only.
     *
     * @test
     */
    public function an_orphaned_category_cannot_be_edited_by_anyone()
    {
        $category = Category::factory()->create(['user_id' => null]);

        $this->assertFalse($this->supplier()->can('update', $category));
        $this->assertFalse($this->doctor()->can('update', $category));
    }

    // =====================================================================
    // Cascades that are intentional
    // =====================================================================

    /** @test */
    public function deleting_an_order_removes_its_line_items()
    {
        $order = Order::factory()->for($this->doctor(), 'doctor')->create();
        $item = OrderItem::factory()->for($order)->for(Product::factory()->for($this->supplier()))->create();

        DB::table('orders')->where('id', $order->id)->delete();

        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
    }

    /**
     * A cart is pre-purchase state with no historical value, so it is swept up
     * with its owner rather than blocking the delete.
     *
     * @test
     */
    public function deleting_a_doctor_removes_their_cart()
    {
        $doctor = $this->doctor();
        $cart = Cart::factory()->create([
            'doctor_id' => $doctor->id,
            'product_id' => Product::factory()->for($this->supplier())->create()->id,
        ]);

        DB::table('users')->where('id', $doctor->id)->delete();

        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
    }

    // =====================================================================
    // Uniqueness
    // =====================================================================

    /**
     * The pair is the ledger key. OrderService looks the row up by
     * (doctor_id, supplier_id) and writes the running balance onto whatever it
     * finds, so a duplicate would split one balance into two and make the
     * remaining amount depend on row order.
     *
     * @test
     */
    public function a_doctor_supplier_pair_can_only_have_one_balance_row()
    {
        $doctor = $this->doctor();
        $supplier = $this->supplier();

        OrderExpense::factory()->create([
            'doctor_id' => $doctor->id,
            'supplier_id' => $supplier->id,
        ]);

        $this->expectDatabaseToReject(
            fn () => DB::table('order_expenses')->insert([
                'doctor_id' => $doctor->id,
                'supplier_id' => $supplier->id,
                'total' => 50,
                'paid' => 0,
                'remaining' => 50,
            ]),
            'a second running-balance row for the same doctor/supplier pair'
        );
    }

    /**
     * FavoriteProductService checks for an existing row and then inserts. Two
     * concurrent requests can both pass that check; the database cannot.
     *
     * @test
     */
    public function a_doctor_cannot_favourite_the_same_product_twice()
    {
        $doctor = $this->doctor();
        $product = Product::factory()->for($this->supplier())->create();

        FavoriteProduct::factory()->create([
            'doctor_id' => $doctor->id,
            'product_id' => $product->id,
        ]);

        $this->expectDatabaseToReject(
            fn () => DB::table('favorite_products')->insert([
                'doctor_id' => $doctor->id,
                'product_id' => $product->id,
            ]),
            'the same product favourited twice by one doctor'
        );
    }

    /** @test */
    public function two_departments_cannot_share_a_code()
    {
        DB::table('departments')->insert(['name' => 'A', 'code' => 'lab']);

        $this->expectDatabaseToReject(
            fn () => DB::table('departments')->insert(['name' => 'B', 'code' => 'lab']),
            'two departments sharing a lookup code'
        );
    }

    /**
     * The code column is nullable and a unique index must not turn "no code"
     * into a value that only one row may hold.
     *
     * @test
     */
    public function departments_without_a_code_do_not_collide()
    {
        DB::table('departments')->insert(['name' => 'A', 'code' => null]);
        DB::table('departments')->insert(['name' => 'B', 'code' => null]);

        $this->assertSame(2, DB::table('departments')->whereNull('code')->count());
    }

    /** @test */
    public function user_email_and_phone_remain_unique()
    {
        $user = $this->doctor();

        $this->expectDatabaseToReject(
            fn () => DB::table('users')->insert([
                'name' => 'dup', 'email' => $user->email, 'phone' => '0000000001', 'password' => 'x',
            ]),
            'a duplicate email address'
        );

        $this->expectDatabaseToReject(
            fn () => DB::table('users')->insert([
                'name' => 'dup', 'email' => 'unique@example.test', 'phone' => $user->phone, 'password' => 'x',
            ]),
            'a duplicate phone number'
        );
    }

    // =====================================================================
    // Role / department structural integrity (Stage 3 boundary)
    // =====================================================================

    /** @test */
    public function every_seeded_role_code_resolves_to_exactly_one_role()
    {
        foreach ([RoleCode::SUPER_ADMIN, RoleCode::DOCTOR, RoleCode::SUPPLIER] as $code) {
            $this->assertSame(1, Role::where('code', $code)->count(), "Role [{$code}] must be unique and present.");
        }
    }

    /**
     * Stage 3 moved authorization onto role_id and kept department_id purely for
     * frontend compatibility. Neither may constrain the other.
     *
     * @test
     */
    public function role_and_department_are_structurally_independent()
    {
        $user = User::factory()->doctor()->withoutDepartment()->create();

        $this->assertNull($user->department_id);
        $this->assertNotNull($user->role_id);
        $this->assertTrue($user->isDoctor());
    }
}
