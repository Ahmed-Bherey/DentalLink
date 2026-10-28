<?php

namespace App\Providers;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Financial\Package;
use App\Models\Financial\Payment;
use App\Models\Financial\Receipt;
use App\Models\General\Category;
use App\Models\General\City;
use App\Models\Shopping\FavoriteProduct;
use App\Models\Store\Product;
use App\Models\User;
use App\Policies\Catalogue\ProductMasterPolicy;
use App\Policies\Financial\CartPolicy;
use App\Policies\Financial\OrderExpensePolicy;
use App\Policies\Financial\OrderItemPolicy;
use App\Policies\Financial\OrderPolicy;
use App\Policies\Financial\PackagePolicy;
use App\Policies\Financial\PaymentPolicy;
use App\Policies\Financial\ReceiptPolicy;
use App\Policies\General\CategoryPolicy;
use App\Policies\General\CityPolicy;
use App\Policies\Shopping\FavoriteProductPolicy;
use App\Policies\Store\ProductPolicy;
use App\Policies\UserPolicy;
use App\Support\RoleCode;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Policy map.
     *
     * Registered explicitly rather than relying on auto-discovery. Laravel's
     * convention would resolve these correctly (App\Models\Financial\Order ->
     * App\Policies\Financial\OrderPolicy), but an explicit map is the single
     * place to see which resources are governed — and, just as importantly,
     * which are not.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        ProductMaster::class => ProductMasterPolicy::class,
        Product::class => ProductPolicy::class,
        Cart::class => CartPolicy::class,
        Order::class => OrderPolicy::class,
        OrderItem::class => OrderItemPolicy::class,
        Payment::class => PaymentPolicy::class,
        OrderExpense::class => OrderExpensePolicy::class,
        Package::class => PackagePolicy::class,
        Receipt::class => ReceiptPolicy::class,
        FavoriteProduct::class => FavoriteProductPolicy::class,
        Category::class => CategoryPolicy::class,
        City::class => CityPolicy::class,
    ];

    /**
     * Abilities a Super Admin may exercise on ANY resource.
     *
     * Deliberately READ-ONLY, and deliberately an allow-list rather than a
     * blanket `return true`.
     *
     * Reasoning: the application has no administrative functionality yet — no
     * admin endpoints, and (per the Stage 3 migration) no super-admin users.
     * Granting write or destructive access now would invent business
     * capabilities that nobody has specified, and would silently let an
     * administrator mutate a supplier's catalogue or a doctor's ledger with no
     * audit trail (there is none until Stage 5).
     *
     * Oversight access is useful and safe; acting as a business party is not.
     * Transactional abilities stay bound to the party that owns the record, so a
     * Super Admin cannot place an order, confirm a payment, or approve a return.
     *
     * Widen this list when the corresponding admin feature is actually built
     * (Product Master administration is Stage 7).
     *
     * @var array<int, string>
     */
    private const SUPER_ADMIN_ABILITIES = [
        'viewAny',
        'view',
        'viewDoctorDirectory',
        'viewSupplierDirectory',
        'viewCounterparty',
        'browseCatalogue',
    ];

    public function boot()
    {
        $this->registerPolicies();

        /*
         * Central Super Admin rule.
         *
         * Returning null (rather than false) for a non-listed ability lets the
         * normal policy run, so a Super Admin who also happens to own a record
         * is still judged on ownership like anyone else.
         */
        Gate::before(function (User $user, string $ability) {
            if (! $user->hasRole(RoleCode::SUPER_ADMIN)) {
                return null;
            }

            return in_array($ability, self::SUPER_ADMIN_ABILITIES, true) ? true : null;
        });

        /*
         * The dashboard is an aggregate view, not a model, so it is a Gate.
         *
         * Doctors and suppliers see THEIR OWN figures; a Super Admin sees
         * platform-wide figures (read-only oversight). A user with no role sees
         * nothing — previously the role branch had no else, so a role-less
         * account received platform-wide totals.
         */
        Gate::define('view-dashboard', function (User $user) {
            return $user->hasRole(RoleCode::DOCTOR, RoleCode::SUPPLIER, RoleCode::SUPER_ADMIN)
                ? Response::allow()
                : Response::deny('عفوا، ليس لديك صلاحية الاطلاع على الإحصائيات.');
        });

        /*
         * Deny-by-default backstop.
         *
         * Gate::after only runs when nothing before it reached a decision, i.e.
         * when an ability has no matching policy method. Without this, such an
         * ability would resolve to "denied" silently; with it, the denial is
         * explicit and carries a message, and a typo'd ability name can never
         * accidentally read as allowed.
         */
        // $result is bool|Response|null, so it is deliberately untyped.
        // Laravel only adopts an after-callback's value when $result was null,
        // which is precisely the "no policy method matched" case.
        Gate::after(function (User $user, string $ability, $result) {
            return $result === null ? false : $result;
        });
    }
}
