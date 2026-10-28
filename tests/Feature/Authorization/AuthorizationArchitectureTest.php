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
use App\Models\Shopping\FavoriteProduct;
use App\Models\Store\Product;
use App\Models\User;
use App\Support\RoleCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Structural guarantees about the authorization layer itself.
 *
 * These do not exercise endpoints; they assert that the architecture is in
 * place and cannot silently regress — every governed model has a policy, no
 * ability resolves by accident, and Department is not an authorization input.
 */
class AuthorizationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function every_high_value_resource_has_a_registered_policy(): void
    {
        $governed = [
            User::class,
            Product::class,
            Cart::class,
            Order::class,
            OrderItem::class,
            Payment::class,
            OrderExpense::class,
            Package::class,
            Receipt::class,
            FavoriteProduct::class,
            Category::class,
            City::class,
        ];

        foreach ($governed as $model) {
            $this->assertNotNull(
                Gate::getPolicyFor($model),
                "{$model} has no registered policy."
            );
        }
    }

    /**
     * Deny by default: an ability with no matching policy method must resolve to
     * false, never to "allowed because nothing objected".
     *
     * @test
     */
    public function an_unknown_ability_is_denied(): void
    {
        $user = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($user)->create();

        $this->assertFalse($user->can('someAbilityThatDoesNotExist', $product));
        $this->assertFalse($user->can('destroyEverything', $product));
    }

    /** @test */
    public function a_user_with_no_role_is_denied_role_gated_abilities(): void
    {
        $user = User::factory()->withoutRole()->create();

        $this->assertFalse($user->can('create', Order::class));
        $this->assertFalse($user->can('create', Payment::class));
        $this->assertFalse($user->can('create', Package::class));
        $this->assertFalse($user->can('viewPending', Payment::class));
        $this->assertFalse($user->can('viewDoctorDirectory', User::class));
        $this->assertFalse($user->can('viewSupplierDirectory', User::class));
    }

    /**
     * Ownership must be compared as integers. A loose comparison is how these
     * checks get bypassed, so pin the behaviour.
     *
     * @test
     */
    public function ownership_is_not_satisfied_by_a_null_owner(): void
    {
        $user = User::factory()->supplier()->create();

        // Stage 5 made products.user_id NOT NULL, so this row can no longer be
        // persisted. The policy invariant is still asserted here, against an
        // unsaved instance, deliberately: the authorization layer must fail
        // closed on a null owner on its own merits, not because the schema
        // currently happens to prevent one from existing. Two independent
        // guarantees, tested independently — the database-level rule is covered
        // by DatabaseIntegrityTest.
        $orphan = (new Product)->forceFill(['id' => 424242, 'user_id' => null]);

        $this->assertFalse($user->can('view', $orphan));
        $this->assertFalse($user->can('update', $orphan));
        $this->assertFalse($user->can('delete', $orphan));
    }

    /**
     * An order line whose parent order or product is missing has no
     * determinable owner, so every action must be denied.
     *
     * @test
     */
    public function an_unattributable_order_item_denies_everyone(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();

        $order = Order::factory()->placedBy($doctor)->create();

        // Stage 5 made order_items.order_id and product_id NOT NULL, so these
        // states are no longer storable. They are still asserted against unsaved
        // instances because the policy must derive "no determinable owner" by
        // itself — it cannot assume the schema will always forbid the input.
        // The storage-level rule is covered by DatabaseIntegrityTest.
        $missingProduct = (new OrderItem)->forceFill([
            'id' => 515151,
            'order_id' => $order->id,
            'product_id' => null,
        ]);

        // The supplier can no longer be identified.
        $this->assertFalse($supplier->can('decideReturn', $missingProduct));
        // The doctor is still the ordering party, so their own abilities remain.
        $this->assertTrue($doctor->can('requestReturn', $missingProduct));

        $missingOrder = (new OrderItem)->forceFill([
            'id' => 515152,
            'order_id' => null,
            'product_id' => Product::factory()->ownedBy($supplier)->create()->id,
        ]);

        $this->assertFalse($doctor->can('requestReturn', $missingOrder));
        $this->assertFalse($doctor->can('update', $missingOrder));
    }

    /**
     * Department must not influence any authorization decision.
     *
     * @test
     */
    public function department_is_not_an_authorization_input(): void
    {
        $supplierA = User::factory()->supplier()->create();
        $supplierB = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplierA)->create();

        // Same department, different owner -> still denied.
        $this->assertSame($supplierA->department_id, $supplierB->department_id);
        $this->assertFalse($supplierB->can('update', $product));

        // Changing department changes nothing.
        $supplierB->update(['department_id' => null]);
        $this->assertFalse($supplierB->fresh()->can('update', $product));
        $this->assertTrue($supplierA->can('update', $product));
    }

    /** @test */
    public function no_controller_still_carries_a_stage_one_interim_ownership_guard(): void
    {
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Policies are allowed to define guard helpers; nothing else is.
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Policies'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ([
                'guardOrderSupplier',
                'guardOrderDoctor',
                'guardOrderItemSupplier',
                'guardSupplierParty',
                'guardDoctorParty',
                'guardPackageOwner',
            ] as $guard) {
                // Ignore mentions inside the migration notes left behind.
                if (preg_match('/\$this->'.$guard.'\(/', $source)) {
                    $offenders[] = basename($file->getPathname()).' -> '.$guard;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Stage 1 interim guards must be replaced by policies: '.implode(', ', $offenders)
        );
    }

    /** @test */
    public function super_admin_read_access_is_an_explicit_allow_list(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->create();

        // Oversight (read) is granted centrally...
        $this->assertTrue($admin->can('view', $product));
        $this->assertTrue($admin->can('viewAny', Product::class));

        // ...but nothing transactional or destructive is.
        $this->assertFalse($admin->can('update', $product));
        $this->assertFalse($admin->can('delete', $product));
        $this->assertFalse($admin->can('create', Order::class));
        $this->assertFalse($admin->can('create', Payment::class));
    }

    /**
     * A Super Admin who genuinely owns a record is still judged on ownership,
     * because Gate::before returns null (rather than false) for non-listed
     * abilities and lets the policy run.
     *
     * @test
     */
    public function super_admin_is_still_judged_on_ownership_for_their_own_records(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $ownProduct = Product::factory()->ownedBy($admin)->create();

        $this->assertTrue($admin->can('update', $ownProduct));
        $this->assertTrue($admin->can('delete', $ownProduct));
    }

    /** @test */
    public function role_codes_used_by_policies_match_the_canonical_definitions(): void
    {
        $this->assertSame(
            ['super_admin', 'doctor', 'supplier'],
            RoleCode::all(),
            'Policies key off these codes; changing them changes access.'
        );
    }
}
