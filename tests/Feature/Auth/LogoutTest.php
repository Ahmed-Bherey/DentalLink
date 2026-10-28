<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Token revocation and lifetime.
 *
 * These deliberately authenticate with a REAL bearer token rather than
 * actingAs(), because actingAs() installs a transient token that is never
 * persisted — it would make a logout test pass without proving anything.
 */
class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): string
    {
        return $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'secret123',
        ])->assertSuccessful()->json('data.token');
    }

    private function doctorWithPassword(): User
    {
        return User::factory()->doctor()->create(['password' => bcrypt('secret123')]);
    }

    /**
     * Drop the resolved guard instances between requests.
     *
     * Laravel keeps one application instance for the whole test, so once a
     * request has authenticated, the guard caches that user and every later
     * request in the same test would be treated as authenticated regardless of
     * the token. Without this, a revocation test would pass vacuously.
     */
    private function forgetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** @test */
    public function a_token_stops_working_after_logout(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->signIn($user);

        // 1. the token works
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profile/show')
            ->assertSuccessful();

        // 2. log out
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertSuccessful();

        // 3. the same token is now rejected
        $this->forgetAuthState();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profile/show')
            ->assertUnauthorized();
    }

    /** @test */
    public function logout_actually_deletes_the_token_row(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->signIn($user);

        $this->assertSame(1, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertSuccessful();

        $this->assertSame(
            0,
            $user->fresh()->tokens()->count(),
            'Logout must revoke the credential, not just return 200.'
        );
    }

    /** @test */
    public function logout_does_not_affect_other_devices(): void
    {
        $user = $this->doctorWithPassword();

        $phone = $this->signIn($user);
        $laptop = $this->signIn($user);

        $this->assertSame(2, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->postJson('/api/logout')
            ->assertSuccessful();

        // The other device stays signed in.
        $this->forgetAuthState();

        $this->withHeader('Authorization', "Bearer {$laptop}")
            ->getJson('/api/profile/show')
            ->assertSuccessful();

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    /** @test */
    public function logout_all_revokes_every_token(): void
    {
        $user = $this->doctorWithPassword();

        $phone = $this->signIn($user);
        $laptop = $this->signIn($user);

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->postJson('/api/logout-all')
            ->assertSuccessful();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->forgetAuthState();

        $this->withHeader('Authorization', "Bearer {$laptop}")
            ->getJson('/api/profile/show')
            ->assertUnauthorized();
    }

    /** @test */
    public function logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->postJson('/api/logout-all')->assertUnauthorized();
    }

    // -------------------------------------------------------- token lifetime

    /** @test */
    public function tokens_have_a_finite_configured_lifetime(): void
    {
        $this->assertIsInt(config('sanctum.expiration'));
        $this->assertGreaterThan(0, config('sanctum.expiration'));
    }

    /** @test */
    public function an_expired_token_is_rejected(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->signIn($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profile/show')
            ->assertSuccessful();

        // Travel past the configured expiry.
        $minutes = (int) config('sanctum.expiration');
        Date::setTestNow(now()->addMinutes($minutes + 1));

        $this->forgetAuthState();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profile/show')
            ->assertUnauthorized();

        Date::setTestNow();
    }

    /** @test */
    public function a_token_is_still_valid_just_before_it_expires(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->signIn($user);

        $minutes = (int) config('sanctum.expiration');
        Date::setTestNow(now()->addMinutes($minutes - 1));

        $this->forgetAuthState();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profile/show')
            ->assertSuccessful();

        Date::setTestNow();
    }

    /** @test */
    public function expired_tokens_can_be_pruned(): void
    {
        $user = $this->doctorWithPassword();
        $this->signIn($user);

        $this->assertSame(1, $user->tokens()->count());

        Date::setTestNow(now()->addMinutes((int) config('sanctum.expiration') + (60 * 48)));

        $this->artisan('sanctum:prune-expired --hours=24')->assertSuccessful();

        $this->assertSame(
            0,
            $user->fresh()->tokens()->count(),
            'Expired token rows must be reapable, or the table grows without bound.'
        );

        Date::setTestNow();
    }
}
