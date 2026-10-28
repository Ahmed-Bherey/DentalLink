<?php

namespace Tests\Feature\Auth;

use App\Models\General\City;
use App\Models\User;
use Database\Factories\General\DepartmentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baseline of the CURRENT authentication behaviour.
 *
 * These document what the application does today so later stages can change it
 * deliberately rather than accidentally. Where current behaviour is known to be
 * wrong, the test asserts the current behaviour and the docblock records the
 * defect and its owning stage.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New User',
            'email' => 'new'.uniqid().'@example.test',
            'phone' => '01'.fake()->unique()->numerify('#########'),
            'address' => '1 Test Street',
            'city_id' => City::factory()->create()->id,
            // Stage 5 made departments.code unique. This helper is called twice
            // by the duplicate-email/phone tests, so it must reuse the canonical
            // row rather than insert a second department with code "doctor" —
            // which is exactly what DepartmentFactory::ofCode() exists for.
            'department_id' => DepartmentFactory::ofCode('doctor')->id,
            'password' => 'secret123',
        ], $overrides);
    }

    // --------------------------------------------------------- registration

    /** @test */
    public function a_visitor_can_register(): void
    {
        $this->postJson('/api/register', $this->registrationPayload(['email' => 'reg@example.test']))
            ->assertSuccessful()
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['status', 'msg', 'data' => ['id', 'name', 'email', 'role']]);

        $this->assertDatabaseHas('users', ['email' => 'reg@example.test']);
    }

    /** @test */
    public function registration_stores_a_hashed_password(): void
    {
        $this->postJson('/api/register', $this->registrationPayload(['email' => 'hash@example.test']))
            ->assertSuccessful();

        $stored = User::where('email', 'hash@example.test')->value('password');

        $this->assertNotSame('secret123', $stored);
        $this->assertTrue(password_verify('secret123', $stored));
    }

    /** @test */
    public function registration_rejects_a_duplicate_email(): void
    {
        $existing = User::factory()->doctor()->create(['email' => 'dupe@example.test']);

        $this->postJson('/api/register', $this->registrationPayload(['email' => $existing->email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** @test */
    public function registration_rejects_a_duplicate_phone(): void
    {
        $existing = User::factory()->doctor()->create();

        $this->postJson('/api/register', $this->registrationPayload(['phone' => $existing->phone]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    /** @test */
    public function registration_requires_the_mandatory_fields(): void
    {
        $this->postJson('/api/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'address', 'city_id', 'department_id', 'password']);
    }

    /**
     * Stage 3 replaced the old min:6 rule with the shared password policy
     * (configurable minimum length, plus a letter and a number).
     *
     * @test
     */
    public function registration_enforces_the_password_policy(): void
    {
        // Too short.
        $this->postJson('/api/register', $this->registrationPayload(['password' => 'Ab1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        // Long enough but no digit.
        $this->postJson('/api/register', $this->registrationPayload(['password' => 'abcdefghij']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        // Long enough but no letter.
        $this->postJson('/api/register', $this->registrationPayload(['password' => '1234567890']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        // The previously-accepted 6-character password is now rejected.
        $this->postJson('/api/register', $this->registrationPayload(['password' => 'abc123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        // A compliant password is accepted.
        $this->postJson('/api/register', $this->registrationPayload(['password' => 'secret123']))
            ->assertSuccessful();
    }

    // ----------------------------------------------------------------- login

    /** @test */
    public function a_user_can_log_in_with_their_email(): void
    {
        $user = User::factory()->doctor()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret123'])
            ->assertSuccessful()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonStructure(['status', 'data' => ['id', 'name', 'email', 'role', 'token']]);
    }

    /** @test */
    public function a_user_can_log_in_with_their_phone(): void
    {
        $user = User::factory()->supplier()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/login', ['login' => $user->phone, 'password' => 'secret123'])
            ->assertSuccessful()
            ->assertJsonPath('data.id', $user->id);
    }

    /** @test */
    public function login_returns_a_usable_api_token(): void
    {
        $user = User::factory()->doctor()->create(['password' => bcrypt('secret123')]);

        $token = $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret123'])
            ->assertSuccessful()
            ->json('data.token');

        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profile/show')
            ->assertSuccessful()
            ->assertJsonPath('data.id', $user->id);
    }

    /** @test */
    public function login_fails_with_a_wrong_password(): void
    {
        $user = User::factory()->doctor()->create(['password' => bcrypt('secret123')]);

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401)
            ->assertJsonPath('status', false);
    }

    /** @test */
    public function login_fails_for_an_unknown_identifier(): void
    {
        $this->postJson('/api/login', ['login' => 'nobody@example.test', 'password' => 'secret123'])
            ->assertStatus(401);
    }

    /** @test */
    public function login_requires_both_fields(): void
    {
        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login', 'password']);
    }

    /** @test */
    public function login_does_not_reveal_whether_an_account_exists(): void
    {
        $user = User::factory()->doctor()->create(['password' => bcrypt('secret123')]);

        $known = $this->postJson('/api/login', ['login' => $user->email, 'password' => 'wrong-password']);
        $unknown = $this->postJson('/api/login', ['login' => 'nobody@example.test', 'password' => 'wrong-password']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    // ----------------------------------------------------------- token guard

    /** @test */
    public function an_unauthenticated_request_to_a_protected_route_is_rejected(): void
    {
        $this->getJson('/api/profile/show')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'يجب تسجيل الدخول أولاً.');
    }

    /** @test */
    public function an_invalid_bearer_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/profile/show')
            ->assertUnauthorized();
    }

    // ------------------------------------------------------ known limitations

    /**
     * RESOLVED IN STAGE 3.
     *
     * This test previously asserted the ABSENCE of revocation. Tokens now expire
     * and a logout route exists, so the assertion is inverted.
     *
     * @test
     */
    public function tokens_expire_and_can_be_revoked(): void
    {
        $this->assertIsInt(
            config('sanctum.expiration'),
            'Sanctum tokens must have a finite lifetime.'
        );
        $this->assertGreaterThan(0, config('sanctum.expiration'));

        $routes = collect(app('router')->getRoutes()->getRoutes())->map->uri();

        $this->assertTrue($routes->contains('api/logout'));
        $this->assertTrue($routes->contains('api/logout-all'));
    }

    /**
     * RESOLVED IN STAGE 3.
     *
     * @test
     */
    public function password_reset_and_change_endpoints_exist(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->map->uri();

        $this->assertTrue($routes->contains('api/password/forgot'));
        $this->assertTrue($routes->contains('api/password/reset'));
        $this->assertTrue($routes->contains('api/password/change'));
    }
}
