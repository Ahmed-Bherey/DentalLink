<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Stage 1 security regression tests.
 *
 * These assert the shape of the application's public attack surface and require
 * no database connection, so they run in any environment.
 */
class PublicAttackSurfaceTest extends TestCase
{
    /**
     * The database backup endpoint executed `mysqldump` via exec() and was
     * registered OUTSIDE the auth:sanctum group, allowing anonymous full-database
     * exfiltration. Both the route and the controller method were removed.
     *
     * @test
     */
    public function database_backup_endpoint_is_not_registered(): void
    {
        $this->assertRouteIsNotRegistered('api/database/backup');
    }

    /** @test */
    public function database_backup_endpoint_is_not_reachable(): void
    {
        $this->getJson('/api/database/backup')->assertNotFound();
    }

    /**
     * The controller method itself must be gone, not merely unrouted, so that
     * re-adding a route cannot resurrect the vulnerability.
     *
     * @test
     */
    public function backup_controller_method_no_longer_exists(): void
    {
        $this->assertFalse(
            method_exists(\App\Http\Controllers\Financial\OrderController::class, 'backupDatabase'),
            'OrderController::backupDatabase() must not exist.'
        );
    }

    /**
     * GET /api/payment/updateExpen zeroed an order_expenses row (total, paid,
     * remaining) with no ownership check and no audit trail.
     *
     * @test
     */
    public function payment_update_expense_endpoint_is_not_registered(): void
    {
        $this->assertRouteIsNotRegistered('api/payment/updateExpen');
    }

    /** @test */
    public function payment_update_expense_endpoint_is_not_reachable(): void
    {
        $this->getJson('/api/payment/updateExpen')->assertNotFound();
    }

    /** @test */
    public function payment_update_expense_controller_method_no_longer_exists(): void
    {
        $this->assertFalse(
            method_exists(\App\Http\Controllers\Financial\PaymentController::class, 'updateExpen'),
            'PaymentController::updateExpen() must not exist.'
        );
    }

    /**
     * Department creation was the privilege-escalation primitive: any authenticated
     * user could mint a new role code, then self-assign it at registration.
     *
     * @test
     */
    public function department_store_endpoint_is_not_registered(): void
    {
        $this->assertRouteIsNotRegistered('api/department/store');
    }

    /** @test */
    public function department_store_endpoint_is_not_reachable(): void
    {
        $this->postJson('/api/department/store', [
            'name' => 'x',
            'code' => 'x',
        ])->assertNotFound();
    }

    /**
     * A debug action that dumped an OrderExpense via dd() with a hardcoded id.
     *
     * @test
     */
    public function debug_expense_dump_action_no_longer_exists(): void
    {
        $this->assertFalse(
            method_exists(\App\Http\Controllers\Financial\OrderController::class, 'showexpen'),
            'OrderController::showexpen() must not exist.'
        );
    }

    /**
     * The unauthenticated surface is deliberately tiny. If a route ever lands
     * outside the auth:sanctum group, this test fails and forces a review.
     *
     * @test
     */
    public function only_the_expected_api_routes_are_unauthenticated(): void
    {
        // Every entry here has been individually reviewed. Adding one requires a
        // deliberate edit to this list, which is the point of the test.
        //
        //  city/index        - non-sensitive reference data (pre-existing)
        //  department/index  - LEGACY registration dropdown; filtered to
        //                      self-assignable codes only (Stage 1)
        //  login / register  - necessarily public
        //  role/index        - registration dropdown; returns ONLY roles flagged
        //                      assignable_at_registration, so privileged role ids
        //                      and codes are never disclosed (Stage 3)
        //  password/policy   - returns the minimum length and a description only;
        //                      no user data, no secrets (Stage 3)
        //  password/forgot   - must be public (the user cannot sign in). Rate
        //                      limited 5/min per IP and 3/min per address, and
        //                      responds identically for unknown addresses so it
        //                      cannot be used to enumerate accounts (Stage 3)
        //  password/reset    - must be public; guarded by a hashed, expiring,
        //                      single-use broker token (Stage 3)
        $expected = [
            'api/city/index',
            'api/department/index',
            'api/login',
            'api/password/forgot',
            'api/password/policy',
            'api/password/reset',
            'api/register',
            'api/role/index',
        ];

        $unauthenticated = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->reject(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->uri())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $expected,
            $unauthenticated,
            'The set of unauthenticated API routes changed. Every addition needs a security review.'
        );
    }

    /**
     * Mutating financial and catalog endpoints must reject anonymous callers at
     * the middleware layer, before any controller logic runs.
     *
     * @test
     *
     * @dataProvider protectedEndpoints
     */
    public function protected_endpoints_reject_unauthenticated_requests(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public static function protectedEndpoints(): array
    {
        return [
            'update product' => ['POST',   '/api/product/update/1'],
            'delete product' => ['DELETE', '/api/product/delete/1'],
            'bulk delete product' => ['POST',   '/api/product/multi-delete'],
            'update order status' => ['POST',   '/api/order/update-status/1'],
            'update item status' => ['POST',   '/api/order/1/update-status'],
            'delete order' => ['DELETE', '/api/order/delete/1'],
            'create payment' => ['POST',   '/api/payment/store'],
            'update payment' => ['POST',   '/api/payment/update/1'],
            'confirm payment' => ['POST',   '/api/payment/confirm/1'],
            'delete payment' => ['GET',    '/api/payment/delete/1'],
            'update package' => ['POST',   '/api/package/update/1'],
            'delete package' => ['DELETE', '/api/package/delete/1'],
            'toggle package' => ['GET',    '/api/package/toggle-status/1'],
            'update cart' => ['POST',   '/api/cart/update/1'],
            'delete cart' => ['DELETE', '/api/cart/delete/1'],
            'doctor details' => ['GET',    '/api/report/doctor/1/details'],
        ];
    }

    private function assertRouteIsNotRegistered(string $uri): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->contains($uri);

        $this->assertFalse($registered, "Route [{$uri}] must not be registered.");
    }
}
