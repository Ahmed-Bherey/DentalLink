<?php

namespace Tests\Feature\Identity;

use App\Models\Role;
use App\Models\User;
use App\Support\RoleCode;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The first-class Role model and its relationship to User.
 */
class RoleModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function the_canonical_roles_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));

        foreach (RoleCode::all() as $code) {
            $this->assertDatabaseHas('roles', ['code' => $code]);
        }

        $this->assertSame(count(RoleCode::all()), Role::count());
    }

    /** @test */
    public function role_codes_are_unique(): void
    {
        $this->expectException(QueryException::class);

        $duplicate = new Role;
        $duplicate->code = RoleCode::DOCTOR;
        $duplicate->name = 'Duplicate Doctor';
        $duplicate->save();
    }

    /** @test */
    public function the_role_seeder_is_idempotent(): void
    {
        $before = Role::count();

        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame($before, Role::count(), 'Re-seeding must never create duplicates.');
    }

    /** @test */
    public function the_role_seeder_preserves_existing_role_ids(): void
    {
        $idsBefore = Role::orderBy('code')->pluck('id', 'code')->all();

        $this->seed(RoleSeeder::class);

        $this->assertSame(
            $idsBefore,
            Role::orderBy('code')->pluck('id', 'code')->all(),
            'Role ids must be stable, or every user assignment would break.'
        );
    }

    /** @test */
    public function only_non_privileged_roles_are_self_assignable(): void
    {
        $this->assertEqualsCanonicalizing(
            [RoleCode::DOCTOR, RoleCode::SUPPLIER],
            Role::selfAssignable()->pluck('code')->all()
        );

        $this->assertFalse(
            Role::query()->where('code', RoleCode::SUPER_ADMIN)->first()->isSelfAssignable()
        );
    }

    /** @test */
    public function a_user_belongs_to_a_role(): void
    {
        $user = User::factory()->doctor()->create();

        $this->assertInstanceOf(Role::class, $user->role);
        $this->assertSame(RoleCode::DOCTOR, $user->role->code);
        $this->assertSame(RoleCode::DOCTOR, $user->roleCode());
    }

    /** @test */
    public function a_role_has_many_users(): void
    {
        $role = Role::query()->where('code', RoleCode::SUPPLIER)->first();

        User::factory()->count(3)->supplier()->create();

        $this->assertSame(3, $role->users()->count());
    }

    /** @test */
    public function role_helpers_report_the_correct_identity(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->assertTrue($doctor->isDoctor());
        $this->assertFalse($doctor->isSupplier());
        $this->assertFalse($doctor->isSuperAdmin());

        $this->assertTrue($supplier->isSupplier());
        $this->assertFalse($supplier->isDoctor());
        $this->assertFalse($supplier->isSuperAdmin());

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertFalse($admin->isDoctor());
        $this->assertFalse($admin->isSupplier());
    }

    /** @test */
    public function has_role_accepts_multiple_codes(): void
    {
        $doctor = User::factory()->doctor()->create();

        $this->assertTrue($doctor->hasRole(RoleCode::DOCTOR, RoleCode::SUPPLIER));
        $this->assertFalse($doctor->hasRole(RoleCode::SUPPLIER, RoleCode::SUPER_ADMIN));
    }

    /**
     * Deny-by-default: a missing role is never a wildcard.
     *
     * @test
     */
    public function a_user_without_a_role_has_no_identity(): void
    {
        $user = User::factory()->withoutRole()->create();

        $this->assertNull($user->roleCode());
        $this->assertFalse($user->isDoctor());
        $this->assertFalse($user->isSupplier());
        $this->assertFalse($user->isSuperAdmin());
        $this->assertFalse($user->hasRole(RoleCode::DOCTOR, RoleCode::SUPPLIER, RoleCode::SUPER_ADMIN));
    }

    /** @test */
    public function the_with_role_scope_filters_users_by_role(): void
    {
        User::factory()->count(2)->doctor()->create();
        User::factory()->count(3)->supplier()->create();
        User::factory()->withoutRole()->create();

        $this->assertSame(2, User::withRole(RoleCode::DOCTOR)->count());
        $this->assertSame(3, User::withRole(RoleCode::SUPPLIER)->count());
        $this->assertSame(5, User::withRole(RoleCode::DOCTOR, RoleCode::SUPPLIER)->count());
        $this->assertSame(0, User::withRole(RoleCode::SUPER_ADMIN)->count());
    }

    /** @test */
    public function the_role_code_is_not_mass_assignable(): void
    {
        $role = Role::query()->where('code', RoleCode::DOCTOR)->first();

        $role->fill(['code' => RoleCode::SUPER_ADMIN]);

        $this->assertSame(RoleCode::DOCTOR, $role->code, 'Role code must never be mass-assignable.');
    }

    /** @test */
    public function the_self_assignable_flag_is_not_mass_assignable(): void
    {
        $role = Role::query()->where('code', RoleCode::SUPER_ADMIN)->first();

        $role->fill(['assignable_at_registration' => true]);

        $this->assertFalse(
            (bool) $role->assignable_at_registration,
            'The self-assignable flag must never be mass-assignable.'
        );
    }
}
