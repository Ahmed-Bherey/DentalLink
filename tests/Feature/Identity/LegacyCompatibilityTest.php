<?php

namespace Tests\Feature\Identity;

use App\Models\Financial\OrderExpense;
use App\Models\Store\Product;
use App\Models\User;
use App\Support\RoleCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Existing doctor and supplier flows must keep working after the identity change.
 *
 * The role source of truth moved from `departments` to `roles`; nothing a real
 * user does should have changed. These tests exercise the role-dependent paths
 * end to end through HTTP.
 */
class LegacyCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function the_login_response_still_reports_the_same_role_values(): void
    {
        foreach ([RoleCode::DOCTOR, RoleCode::SUPPLIER] as $code) {
            $user = User::factory()->withRole($code)->create(['password' => bcrypt('secret123')]);

            $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret123'])
                ->assertSuccessful()
                ->assertJsonPath('data.role', $code);

            $this->app['auth']->forgetGuards();
        }
    }

    /** @test */
    public function the_login_response_still_carries_the_legacy_department_id(): void
    {
        $user = User::factory()->doctor()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret123'])
            ->assertSuccessful()
            ->assertJsonPath('data.department_id', $user->department_id);
    }

    /** @test */
    public function the_profile_response_reports_role_and_department_separately(): void
    {
        $user = User::factory()->supplier()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile/show')
            ->assertSuccessful()
            ->assertJsonPath('data.role', RoleCode::SUPPLIER)
            ->assertJsonPath('data.role_details.code', RoleCode::SUPPLIER)
            ->assertJsonPath('data.department_id', $user->department_id)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'email', 'role', 'role_details', 'department_id', 'department'],
            ]);
    }

    /**
     * NOTE: unlike `roles`, the legacy `departments` table is NOT seeded by any
     * migration — it is pre-existing production data. A fresh install therefore
     * has an empty department list, which is fine because new frontends read
     * /api/role/index. This test seeds the legacy rows explicitly to reproduce
     * an upgraded environment.
     *
     * @test
     */
    public function the_legacy_department_index_endpoint_still_works(): void
    {
        $this->seed(\Database\Seeders\TestReferenceDataSeeder::class);

        $codes = collect($this->getJson('/api/department/index')->assertSuccessful()->json('data'))
            ->pluck('role')
            ->all();

        $this->assertEqualsCanonicalizing([RoleCode::DOCTOR, RoleCode::SUPPLIER], $codes);
    }

    /**
     * A fresh install has no legacy departments, and that must not be an error.
     *
     * @test
     */
    public function the_legacy_department_index_returns_an_empty_list_on_a_fresh_install(): void
    {
        $this->assertSame(0, \App\Models\General\Department::count());

        $this->getJson('/api/department/index')
            ->assertSuccessful()
            ->assertJsonPath('data', []);
    }

    // ------------------------------------------------- role-dependent flows

    /** @test */
    public function a_doctor_can_still_place_an_order(): void
    {
        $doctor = User::factory()->doctor()->create();
        $product = Product::factory()->ownedBy(User::factory()->supplier()->create())->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $product->id, 'quantity' => 1]],
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', ['doctor_id' => $doctor->id]);
    }

    /** @test */
    public function a_supplier_still_cannot_place_an_order(): void
    {
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $product->id, 'quantity' => 1]],
            ])
            ->assertStatus(403);
    }

    /** @test */
    public function a_doctor_can_still_browse_supplier_products(): void
    {
        $doctor = User::factory()->doctor()->create();
        Product::factory()->ownedBy(User::factory()->supplier()->create())->create();

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/product/all')
            ->assertSuccessful();

        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * The shopping list excludes doctor-owned inventory. That filter now reads
     * the role instead of the department.
     *
     * @test
     */
    public function the_shopping_list_still_excludes_doctor_owned_products(): void
    {
        $doctor = User::factory()->doctor()->create();

        Product::factory()->ownedBy(User::factory()->supplier()->create())->create();
        Product::factory()->ownedBy($doctor)->create();

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/product/all')
            ->assertSuccessful();

        $this->assertSame(1, $response->json('meta.total'), 'Only the supplier product is listed.');
    }

    /** @test */
    public function a_supplier_still_cannot_use_the_doctor_shopping_endpoint(): void
    {
        $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->getJson('/api/product/all')
            ->assertStatus(403);
    }

    /** @test */
    public function a_doctor_can_still_see_their_pending_payments(): void
    {
        $doctor = User::factory()->doctor()->create();

        $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/payment/pending')
            ->assertSuccessful();
    }

    /** @test */
    public function a_supplier_still_cannot_use_the_doctor_pending_payments_endpoint(): void
    {
        $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->getJson('/api/payment/pending')
            ->assertStatus(403);
    }

    /** @test */
    public function the_doctor_report_still_lists_only_doctors(): void
    {
        $supplier = User::factory()->supplier()->create();
        User::factory()->count(2)->doctor()->create();
        User::factory()->count(3)->supplier()->create();

        $response = $this->actingAs($supplier, 'sanctum')
            ->getJson('/api/report/doctor/all')
            ->assertSuccessful();

        $this->assertSame(2, $response->json('meta.total'));
    }

    /** @test */
    public function the_supplier_report_still_lists_only_suppliers(): void
    {
        $doctor = User::factory()->doctor()->create();
        User::factory()->count(4)->supplier()->create();
        User::factory()->count(2)->doctor()->create();

        $response = $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/report/supplier/all')
            ->assertSuccessful();

        $this->assertSame(4, $response->json('meta.total'));
    }

    /** @test */
    public function the_dashboard_still_reports_role_specific_statistics(): void
    {
        $doctor = User::factory()->doctor()->create();
        User::factory()->count(3)->supplier()->create();

        $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/statistic')
            ->assertSuccessful()
            ->assertJsonPath('data.total_users', 3);
    }

    /** @test */
    public function the_payment_counterparty_must_still_be_a_doctor(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        OrderExpense::factory()->between($doctor, $supplier)->withBalance(1000, 0)->create();

        // A doctor counterparty is accepted.
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $doctor->id,
                'paid' => 100,
                'date' => now()->toDateString(),
            ])
            ->assertSuccessful();

        // A supplier counterparty is not. This validation now resolves the
        // doctor role through the roles table.
        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => User::factory()->supplier()->create()->id,
                'paid' => 100,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('doctor_id');
    }
}
