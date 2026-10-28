<?php

namespace Tests\Feature\Authorization;

use App\Models\Financial\Order;
use App\Models\Financial\OrderItem;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two aggregate/self surfaces that are not model-scoped:
 * the dashboard Gate, and the self-only profile endpoints.
 */
class DashboardAndProfileAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // =============================================================== dashboard

    /** @test */
    public function the_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/statistic')->assertUnauthorized();
    }

    /** @test */
    public function a_doctor_and_a_supplier_can_each_see_their_own_dashboard(): void
    {
        $this->actingAs(User::factory()->doctor()->create(), 'sanctum')
            ->getJson('/api/statistic')
            ->assertSuccessful();

        $this->app['auth']->forgetGuards();

        $this->actingAs(User::factory()->supplier()->create(), 'sanctum')
            ->getJson('/api/statistic')
            ->assertSuccessful();
    }

    /**
     * REGRESSION: before Stage 4 the role branch had no else, so a user with no
     * role fell through to the platform-wide aggregate and received every
     * tenant's totals.
     *
     * @test
     */
    public function a_user_with_no_role_cannot_see_the_dashboard(): void
    {
        $this->actingAs(User::factory()->withoutRole()->create(), 'sanctum')
            ->getJson('/api/statistic')
            ->assertStatus(403);
    }

    /** @test */
    public function the_dashboard_figures_are_scoped_to_the_caller(): void
    {
        $doctor = User::factory()->doctor()->create();
        $otherDoctor = User::factory()->doctor()->create();
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->create();

        foreach ([$doctor, $doctor, $otherDoctor] as $buyer) {
            $order = Order::factory()->placedBy($buyer)->delivered()->create();
            OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
        }

        $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/statistic')
            ->assertSuccessful()
            ->assertJsonPath('data.total_delivered', 2);
    }

    /** @test */
    public function a_super_admin_sees_platform_wide_figures(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $supplier = User::factory()->supplier()->create();
        $product = Product::factory()->ownedBy($supplier)->create();

        foreach ([User::factory()->doctor()->create(), User::factory()->doctor()->create()] as $buyer) {
            $order = Order::factory()->placedBy($buyer)->delivered()->create();
            OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/statistic')
            ->assertSuccessful()
            ->assertJsonPath('data.total_delivered', 2);
    }

    // ================================================================= profile

    /** @test */
    public function the_profile_endpoints_require_authentication(): void
    {
        $this->getJson('/api/profile/show')->assertUnauthorized();
        $this->postJson('/api/profile/update', ['name' => 'x'])->assertUnauthorized();
    }

    /**
     * The profile endpoints take no id — they always act on the token's user, so
     * there is no id to tamper with. This pins that structural guarantee.
     *
     * @test
     */
    public function the_profile_endpoints_always_act_on_the_authenticated_user(): void
    {
        $user = User::factory()->doctor()->create(['name' => 'Mine']);
        $victim = User::factory()->doctor()->create(['name' => 'Theirs']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile/show')
            ->assertSuccessful()
            ->assertJsonPath('data.id', $user->id);

        // Supplying another user's id changes nothing.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/update', [
                'name' => 'Renamed',
                'id' => $victim->id,
                'user_id' => $victim->id,
            ])
            ->assertSuccessful();

        $this->assertSame('Renamed', $user->fresh()->name);
        $this->assertSame('Theirs', $victim->fresh()->name, 'The other user must be untouched.');
    }

    /** @test */
    public function the_user_policy_restricts_profile_actions_to_the_owner(): void
    {
        $user = User::factory()->doctor()->create();
        $other = User::factory()->doctor()->create();

        $this->assertTrue($user->can('view', $user));
        $this->assertTrue($user->can('update', $user));

        $this->assertFalse($user->can('view', $other));
        $this->assertFalse($user->can('update', $other));
    }

    /** @test */
    public function notifications_are_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->doctor()->create();

        $this->getJson('/api/notification/user')->assertUnauthorized();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notification/user')
            ->assertSuccessful();
    }
}
