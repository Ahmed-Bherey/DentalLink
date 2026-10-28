<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Password reset via Laravel's native broker.
 *
 * No real mail is sent: Notification::fake() intercepts delivery and the token
 * is read from the captured notification.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function doctorWithPassword(): User
    {
        return User::factory()->doctor()->create(['password' => bcrypt('secret123')]);
    }

    /** Requests a link and returns the raw broker token. */
    private function requestResetToken(User $user): string
    {
        Notification::fake();

        $this->postJson('/api/password/forgot', ['email' => $user->email])
            ->assertSuccessful();

        $token = null;

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->assertNotNull($token);

        return $token;
    }

    // ------------------------------------------------------------- requesting

    /** @test */
    public function a_reset_link_is_sent_for_a_known_address(): void
    {
        Notification::fake();
        $user = $this->doctorWithPassword();

        $this->postJson('/api/password/forgot', ['email' => $user->email])
            ->assertSuccessful();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseHas('password_resets', ['email' => $user->email]);
    }

    /**
     * The endpoint must not become an account-enumeration oracle.
     *
     * @test
     */
    public function an_unknown_address_gets_an_identical_response(): void
    {
        Notification::fake();
        $user = $this->doctorWithPassword();

        $known = $this->postJson('/api/password/forgot', ['email' => $user->email]);
        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.test']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());

        Notification::assertNothingSentTo(
            User::factory()->makeOne(['email' => 'nobody@example.test'])
        );
    }

    /** @test */
    public function requesting_a_reset_validates_the_address(): void
    {
        $this->postJson('/api/password/forgot', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/password/forgot', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** @test */
    public function no_real_mail_is_dispatched_during_tests(): void
    {
        Notification::fake();
        $user = $this->doctorWithPassword();

        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertSuccessful();

        Notification::assertCount(1);
    }

    // -------------------------------------------------------------- resetting

    /** @test */
    public function a_valid_token_resets_the_password(): void
    {
        Event::fake([PasswordReset::class]);

        $user = $this->doctorWithPassword();
        $token = $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('brandNew123', $user->fresh()->password));
        Event::assertDispatched(PasswordReset::class);
    }

    /** @test */
    public function the_new_password_works_for_login(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertSuccessful();

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret123'])
            ->assertStatus(401);

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'brandNew123'])
            ->assertSuccessful();
    }

    /** @test */
    public function a_reset_revokes_all_existing_tokens(): void
    {
        $user = $this->doctorWithPassword();

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret123'])->assertSuccessful();
        $this->assertSame(1, $user->tokens()->count());

        $token = $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertSuccessful();

        $this->assertSame(
            0,
            $user->fresh()->tokens()->count(),
            'A reset implies possible compromise; existing API tokens must be dropped.'
        );
    }

    /** @test */
    public function an_invalid_token_is_rejected(): void
    {
        $user = $this->doctorWithPassword();
        $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'token' => 'completely-made-up-token',
            'email' => $user->email,
            'password' => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    /** @test */
    public function an_expired_token_is_rejected(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->requestResetToken($user);

        // Broker tokens expire after config('auth.passwords.users.expire') minutes.
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));

        $this->travelBack();
    }

    /** @test */
    public function a_token_cannot_be_reused(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brandNew123',
            'password_confirmation' => 'brandNew123',
        ])->assertSuccessful();

        // Second use of the same token must fail.
        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'anotherOne456',
            'password_confirmation' => 'anotherOne456',
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertTrue(
            Hash::check('brandNew123', $user->fresh()->password),
            'The second reset must not have taken effect.'
        );
    }

    /** @test */
    public function a_token_cannot_be_used_for_a_different_account(): void
    {
        $victim = $this->doctorWithPassword();
        $attacker = User::factory()->doctor()->create(['password' => bcrypt('secret123')]);

        $attackerToken = $this->requestResetToken($attacker);

        $this->postJson('/api/password/reset', [
            'token' => $attackerToken,
            'email' => $victim->email,
            'password' => 'hijacked123',
            'password_confirmation' => 'hijacked123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('secret123', $victim->fresh()->password));
    }

    /** @test */
    public function a_weak_password_is_rejected_on_reset(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    /** @test */
    public function a_mismatched_confirmation_is_rejected_on_reset(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brandNew123',
            'password_confirmation' => 'different456',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    /** @test */
    public function the_stored_reset_token_is_hashed(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->requestResetToken($user);

        $stored = \Illuminate\Support\Facades\DB::table('password_resets')
            ->where('email', $user->email)
            ->value('token');

        $this->assertNotSame($token, $stored, 'The raw token must never be stored.');
        $this->assertTrue(Hash::check($token, $stored));
    }

    /** @test */
    public function the_broker_is_configured_with_an_expiry(): void
    {
        $this->assertIsInt(config('auth.passwords.users.expire'));
        $this->assertGreaterThan(0, config('auth.passwords.users.expire'));
        $this->assertNotNull(Password::broker());
    }
}
