<?php

namespace Tests\Feature\Identity;

use App\Models\General\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\RoleCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Super Admin identity.
 *
 * Stage 3 establishes only the IDENTITY representation. What a Super Admin is
 * permitted to do is Stage 4's job; these tests assert that the identity exists,
 * is explicit, and cannot be self-claimed.
 */
class SuperAdminIdentityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function super_admin_is_an_explicit_role(): void
    {
        $role = Role::query()->where('code', RoleCode::SUPER_ADMIN)->first();

        $this->assertNotNull($role, 'A super_admin role must exist as first-class data.');
        $this->assertTrue($role->isSuperAdmin());
        $this->assertFalse($role->isSelfAssignable());
    }

    /**
     * Super Admin must not be inferable from any department value.
     *
     * @test
     */
    public function super_admin_does_not_depend_on_any_department(): void
    {
        $admin = User::factory()->superAdmin()->create(['department_id' => null]);

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertNull($admin->department_id);

        // Naming a department "super_admin" grants nothing.
        $decoy = Department::factory()->create(['code' => RoleCode::SUPER_ADMIN, 'name' => 'Super Admin']);
        $impostor = User::factory()->doctor()->create(['department_id' => $decoy->id]);

        $this->assertFalse(
            $impostor->isSuperAdmin(),
            'A department named super_admin must never confer Super Admin.'
        );
        $this->assertTrue($impostor->isDoctor());
    }

    /** @test */
    public function super_admin_cannot_be_claimed_at_registration(): void
    {
        $superAdminRole = Role::query()->where('code', RoleCode::SUPER_ADMIN)->first();

        $this->postJson('/api/register', $this->registrationPayload([
            'role_id' => $superAdminRole->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('role_id');

        $this->assertSame(0, User::withRole(RoleCode::SUPER_ADMIN)->count());
    }

    /** @test */
    public function super_admin_cannot_be_claimed_through_the_legacy_department_field(): void
    {
        $decoy = Department::factory()->create(['code' => RoleCode::SUPER_ADMIN, 'name' => 'Super Admin']);

        $this->postJson('/api/register', $this->registrationPayload([
            'department_id' => $decoy->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->assertSame(0, User::withRole(RoleCode::SUPER_ADMIN)->count());
    }

    /** @test */
    public function an_ordinary_user_cannot_promote_themselves_via_the_profile_endpoint(): void
    {
        $user = User::factory()->doctor()->create();
        $superAdminRole = Role::query()->where('code', RoleCode::SUPER_ADMIN)->first();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/update', [
                'name' => 'Renamed',
                'role_id' => $superAdminRole->id,
                'role' => RoleCode::SUPER_ADMIN,
            ])
            ->assertSuccessful();

        $fresh = $user->fresh();

        $this->assertTrue($fresh->isDoctor());
        $this->assertFalse($fresh->isSuperAdmin());
        $this->assertSame('Renamed', $fresh->name, 'Legitimate fields still update.');
    }

    /** @test */
    public function there_is_no_http_endpoint_that_assigns_a_role(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->reject(fn ($route) => in_array($route->methods()[0], ['GET', 'HEAD'], true))
            ->map(fn ($route) => $route->uri())
            ->all();

        foreach ($uris as $uri) {
            $this->assertStringNotContainsString('role/store', $uri);
            $this->assertStringNotContainsString('role/update', $uri);
            $this->assertStringNotContainsString('role/assign', $uri);
        }

        // The role endpoint is read-only.
        $this->assertTrue(collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($r) => $r->uri() === 'api/role/index' && in_array('GET', $r->methods(), true)));
    }

    /** @test */
    public function the_public_role_list_never_exposes_privileged_roles(): void
    {
        $codes = collect($this->getJson('/api/role/index')->assertSuccessful()->json('data'))
            ->pluck('code')
            ->all();

        $this->assertEqualsCanonicalizing([RoleCode::DOCTOR, RoleCode::SUPPLIER], $codes);
        $this->assertNotContains(RoleCode::SUPER_ADMIN, $codes);
    }

    // ------------------------------------------------- console-only assignment

    /** @test */
    public function the_console_command_can_grant_super_admin(): void
    {
        $user = User::factory()->doctor()->create();

        $this->artisan('identity:assign-role', [
            'user' => $user->id,
            'role' => RoleCode::SUPER_ADMIN,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue($user->fresh()->isSuperAdmin());
    }

    /** @test */
    public function the_console_command_rejects_an_unknown_role(): void
    {
        $user = User::factory()->doctor()->create();

        $this->artisan('identity:assign-role', [
            'user' => $user->id,
            'role' => 'not-a-role',
            '--force' => true,
        ])->assertFailed();

        $this->assertTrue($user->fresh()->isDoctor());
    }

    /** @test */
    public function the_role_audit_command_reports_users_without_a_role(): void
    {
        User::factory()->doctor()->create();
        User::factory()->withoutRole()->create();

        // Non-zero exit signals unresolved identities.
        $this->artisan('identity:audit-roles')->assertFailed();

        User::whereNull('role_id')->delete();

        $this->artisan('identity:audit-roles')->assertSuccessful();
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Person',
            'email' => 'person'.uniqid().'@example.test',
            'phone' => '01'.fake()->unique()->numerify('#########'),
            'address' => '1 Test Street',
            'city_id' => \App\Models\General\City::factory()->create()->id,
            'password' => 'secret123',
        ], $overrides);
    }
}
