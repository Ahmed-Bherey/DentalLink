<?php

namespace Tests\Feature\Identity;

use App\Models\General\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\RoleCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registration assigns identity, and cannot be used to escalate.
 *
 * Also covers the legacy `department_id` compatibility path, which must keep
 * working until the frontend migrates to `role_id`.
 */
class RegistrationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
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

    private function roleId(string $code): int
    {
        return Role::query()->where('code', $code)->value('id');
    }

    // ------------------------------------------------------------ new path

    /** @test */
    public function a_visitor_can_register_with_a_role_id(): void
    {
        $this->postJson('/api/register', $this->payload([
            'role_id' => $this->roleId(RoleCode::DOCTOR),
            'email' => 'newdoctor@example.test',
        ]))->assertSuccessful();

        $user = User::where('email', 'newdoctor@example.test')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->isDoctor());
        $this->assertSame($this->roleId(RoleCode::DOCTOR), $user->role_id);
    }

    /** @test */
    public function a_supplier_can_register(): void
    {
        $this->postJson('/api/register', $this->payload([
            'role_id' => $this->roleId(RoleCode::SUPPLIER),
            'email' => 'newsupplier@example.test',
        ]))->assertSuccessful();

        $this->assertTrue(User::where('email', 'newsupplier@example.test')->first()->isSupplier());
    }

    /** @test */
    public function registration_requires_an_account_type(): void
    {
        $this->postJson('/api/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_id', 'department_id']);

        $this->assertSame(0, User::count());
    }

    /** @test */
    public function registration_rejects_an_unknown_role_id(): void
    {
        $this->postJson('/api/register', $this->payload(['role_id' => 99999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');
    }

    /** @test */
    public function the_response_reports_the_authoritative_role(): void
    {
        $this->postJson('/api/register', $this->payload([
            'role_id' => $this->roleId(RoleCode::SUPPLIER),
        ]))
            ->assertSuccessful()
            ->assertJsonPath('data.role', RoleCode::SUPPLIER);
    }

    // --------------------------------------------------------- legacy path

    /** @test */
    public function a_visitor_can_still_register_with_the_legacy_department_id(): void
    {
        $department = Department::query()->where('code', RoleCode::DOCTOR)->first()
            ?? Department::factory()->create(['code' => RoleCode::DOCTOR, 'name' => 'Doctor']);

        $this->postJson('/api/register', $this->payload([
            'department_id' => $department->id,
            'email' => 'legacy@example.test',
        ]))->assertSuccessful();

        $user = User::where('email', 'legacy@example.test')->first();

        // The legacy field still resolves to the correct AUTHORITATIVE role...
        $this->assertTrue($user->isDoctor());
        $this->assertSame($this->roleId(RoleCode::DOCTOR), $user->role_id);
        // ...and the legacy column is still populated for existing consumers.
        $this->assertSame($department->id, $user->department_id);
    }

    /** @test */
    public function the_legacy_department_path_cannot_escalate(): void
    {
        $privileged = Department::factory()->create(['code' => 'admin', 'name' => 'Admin']);

        $this->postJson('/api/register', $this->payload(['department_id' => $privileged->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_id');

        $this->assertSame(0, User::count());
    }

    /**
     * A department whose code matches no role cannot be used to sneak in a user
     * with no role at all.
     *
     * @test
     */
    public function an_unmapped_department_is_rejected(): void
    {
        $unmapped = Department::factory()->create(['code' => 'procurement', 'name' => 'Procurement']);

        $this->postJson('/api/register', $this->payload(['department_id' => $unmapped->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_id');

        $this->assertSame(0, User::count());
    }

    // ----------------------------------------------------- escalation attempts

    /** @test */
    public function extra_identity_fields_in_the_payload_are_ignored(): void
    {
        $this->postJson('/api/register', $this->payload([
            'role_id' => $this->roleId(RoleCode::DOCTOR),
            'email' => 'sneaky@example.test',
            // None of these may take effect.
            'role' => RoleCode::SUPER_ADMIN,
            'role_code' => RoleCode::SUPER_ADMIN,
            'is_admin' => true,
            'active' => 0,
        ]))->assertSuccessful();

        $user = User::where('email', 'sneaky@example.test')->first();

        $this->assertTrue($user->isDoctor());
        $this->assertFalse($user->isSuperAdmin());
        $this->assertEquals(1, $user->active, 'active must keep its database default.');
    }

    /**
     * Defence in depth: even called directly, the service refuses a privileged
     * role. The FormRequest is not the only thing standing in the way.
     *
     * @test
     */
    public function the_service_layer_refuses_a_privileged_role(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Services\Auth\AuthService::class)->register([
            'name' => 'Direct Call',
            'email' => 'direct@example.test',
            'phone' => '01999888777',
            'password' => 'secret123',
            'role_id' => $this->roleId(RoleCode::SUPER_ADMIN),
        ]);
    }

    /** @test */
    public function the_service_layer_refuses_a_missing_role(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Services\Auth\AuthService::class)->register([
            'name' => 'No Role',
            'email' => 'norole@example.test',
            'phone' => '01999888666',
            'password' => 'secret123',
        ]);
    }
}
