<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function doctorWithPassword(): User
    {
        return User::factory()->doctor()->create(['password' => bcrypt('secret123')]);
    }

    private function signIn(User $user): string
    {
        return $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'secret123',
        ])->assertSuccessful()->json('data.token');
    }

    private function forgetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** @test */
    public function a_user_can_change_their_password(): void
    {
        $user = $this->doctorWithPassword();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/password/change', [
                'current_password' => 'secret123',
                'password' => 'newSecret456',
                'password_confirmation' => 'newSecret456',
            ])
            ->assertSuccessful();

        $this->assertTrue(Hash::check('newSecret456', $user->fresh()->password));
        $this->assertFalse(Hash::check('secret123', $user->fresh()->password));
    }

    /** @test */
    public function the_new_password_works_for_login_and_the_old_one_does_not(): void
    {
        $user = $this->doctorWithPassword();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/password/change', [
                'current_password' => 'secret123',
                'password' => 'newSecret456',
                'password_confirmation' => 'newSecret456',
            ])
            ->assertSuccessful();

        $this->forgetAuthState();

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret123'])
            ->assertStatus(401);

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'newSecret456'])
            ->assertSuccessful();
    }

    /**
     * A password change must invalidate credentials issued before it, otherwise
     * a stolen token survives the very event meant to contain it.
     *
     * @test
     */
    public function changing_the_password_revokes_all_existing_tokens(): void
    {
        $user = $this->doctorWithPassword();
        $token = $this->signIn($user);
        $this->signIn($user);

        $this->assertSame(2, $user->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/password/change', [
                'current_password' => 'secret123',
                'password' => 'newSecret456',
                'password_confirmation' => 'newSecret456',
            ])
            ->assertSuccessful();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->forgetAuthState();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/profile/show')
            ->assertUnauthorized();
    }

    /** @test */
    public function an_incorrect_current_password_is_rejected(): void
    {
        $user = $this->doctorWithPassword();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/password/change', [
                'current_password' => 'not-the-password',
                'password' => 'newSecret456',
                'password_confirmation' => 'newSecret456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    /** @test */
    public function a_weak_new_password_is_rejected(): void
    {
        $user = $this->doctorWithPassword();

        foreach (['ab1', 'abcdefghij', '1234567890'] as $weak) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/password/change', [
                    'current_password' => 'secret123',
                    'password' => $weak,
                    'password_confirmation' => $weak,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('password');
        }

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    /** @test */
    public function a_mismatched_confirmation_is_rejected(): void
    {
        $user = $this->doctorWithPassword();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/password/change', [
                'current_password' => 'secret123',
                'password' => 'newSecret456',
                'password_confirmation' => 'differentSecret789',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('secret123', $user->fresh()->password));
    }

    /** @test */
    public function reusing_the_current_password_is_rejected(): void
    {
        $user = $this->doctorWithPassword();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/password/change', [
                'current_password' => 'secret123',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /** @test */
    public function an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/password/change', [
            'current_password' => 'secret123',
            'password' => 'newSecret456',
            'password_confirmation' => 'newSecret456',
        ])->assertUnauthorized();
    }

    /** @test */
    public function the_password_is_stored_hashed(): void
    {
        $user = $this->doctorWithPassword();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/password/change', [
                'current_password' => 'secret123',
                'password' => 'newSecret456',
                'password_confirmation' => 'newSecret456',
            ])
            ->assertSuccessful();

        $stored = $user->fresh()->password;

        $this->assertNotSame('newSecret456', $stored);
        $this->assertStringStartsWith('$2y$', $stored);
    }

    /** @test */
    public function the_password_policy_is_discoverable(): void
    {
        $this->getJson('/api/password/policy')
            ->assertSuccessful()
            ->assertJsonPath('data.min_length', config('auth.password_policy.min_length'))
            ->assertJsonStructure(['status', 'data' => ['min_length', 'description']]);
    }
}
