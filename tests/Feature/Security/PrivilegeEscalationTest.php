<?php

namespace Tests\Feature\Security;

use App\Models\General\City;
use App\Models\General\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression suite for the privilege-escalation chain closed in Stage 1.
 *
 * The original chain was: read the public department list to discover role ids
 * -> self-assign a privileged department_id at registration -> land an active
 * account with elevated access. Each link is asserted broken here.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Person',
            'email' => 'person'.uniqid().'@example.test',
            'phone' => '01'.fake()->unique()->numerify('#########'),
            'address' => '1 Test Street',
            'city_id' => City::factory()->create()->id,
            'password' => 'secret123',
        ], $overrides);
    }

    /** @test */
    public function registration_rejects_a_privileged_department(): void
    {
        $privileged = Department::factory()->create(['code' => 'admin', 'name' => 'Admin']);

        $this->postJson('/api/register', $this->registrationPayload([
            'department_id' => $privileged->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_id');

        $this->assertDatabaseMissing('users', ['department_id' => $privileged->id]);
    }

    /** @test */
    public function registration_rejects_an_unknown_department(): void
    {
        $this->postJson('/api/register', $this->registrationPayload([
            'department_id' => 99999,
        ]))->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    /**
     * A NULL department bypassed every role filter in the application, so the
     * field must be mandatory.
     *
     * @test
     */
    public function registration_requires_a_department(): void
    {
        $payload = $this->registrationPayload();
        unset($payload['department_id']);

        $this->postJson('/api/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_id');

        $this->assertDatabaseCount('users', 0);
    }

    /** @test */
    public function registration_accepts_a_self_assignable_department(): void
    {
        $doctor = Department::factory()->create(['code' => 'doctor', 'name' => 'Doctor']);

        $this->postJson('/api/register', $this->registrationPayload([
            'department_id' => $doctor->id,
        ]))->assertSuccessful();

        $this->assertDatabaseHas('users', ['department_id' => $doctor->id]);
    }

    /** @test */
    public function the_public_department_list_does_not_expose_privileged_codes(): void
    {
        Department::factory()->create(['code' => 'admin', 'name' => 'Admin']);
        Department::factory()->create(['code' => 'doctor', 'name' => 'Doctor']);
        Department::factory()->create(['code' => 'supplier', 'name' => 'Supplier']);

        $codes = collect($this->getJson('/api/department/index')->assertSuccessful()->json('data'))
            ->pluck('role')
            ->all();

        $this->assertNotContains('admin', $codes);
        $this->assertEqualsCanonicalizing(['doctor', 'supplier'], $codes);
    }

    /** @test */
    public function an_authenticated_user_cannot_create_a_department(): void
    {
        $user = User::factory()->doctor()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/department/store', ['name' => 'Admin', 'code' => 'admin'])
            ->assertNotFound();

        $this->assertDatabaseMissing('departments', ['code' => 'admin']);
    }

    // -------------------------------------------------- mass assignment

    /** @test */
    public function the_active_flag_cannot_be_mass_assigned_at_registration(): void
    {
        $doctor = Department::factory()->create(['code' => 'doctor', 'name' => 'Doctor']);

        $this->postJson('/api/register', $this->registrationPayload([
            'department_id' => $doctor->id,
            'active' => 0,
        ]))->assertSuccessful();

        // The payload value must be ignored; the column keeps its database default.
        $this->assertEquals(1, User::first()->active);
    }

    /** @test */
    public function a_user_cannot_change_their_department_through_the_profile_endpoint(): void
    {
        $user = User::factory()->doctor()->create();
        $original = $user->department_id;
        $privileged = Department::factory()->create(['code' => 'admin', 'name' => 'Admin']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/update', [
                'name' => 'Renamed',
                'department_id' => $privileged->id,
                'active' => 1,
            ])
            ->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertSame($original, $fresh->department_id, 'department_id must not be updatable via the profile endpoint.');
        $this->assertSame('Renamed', $fresh->name, 'Legitimate fields must still update.');
    }

    /** @test */
    public function a_client_cannot_set_the_order_status_when_creating_an_order(): void
    {
        $doctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $product = \App\Models\Store\Product::factory()->ownedBy($supplier)->create();

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/order/store', [
                'products' => [['id' => $product->id, 'quantity' => 1]],
                'status' => 'delivered',
            ])
            ->assertSuccessful();

        $this->assertSame('pending', \App\Models\Financial\Order::first()->status);
    }

    /** @test */
    public function a_supplier_cannot_record_a_payment_against_a_non_doctor_account(): void
    {
        $supplier = User::factory()->supplier()->create();
        $otherSupplier = User::factory()->supplier()->create();

        $this->actingAs($supplier, 'sanctum')
            ->postJson('/api/payment/store', [
                'doctor_id' => $otherSupplier->id,
                'paid' => 100,
                'date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('doctor_id');

        $this->assertDatabaseCount('payments', 0);
    }
}
