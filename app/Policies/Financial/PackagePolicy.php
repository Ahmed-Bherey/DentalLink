<?php

namespace App\Policies\Financial;

use App\Models\Financial\Package;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * Ownership: packages.supplier_id.
 *
 * A package is a supplier's product bundle. It is offered publicly to doctors
 * through the shopping list, so `view` is intentionally NOT owner-restricted —
 * a doctor must be able to see what they are buying. Mutation is owner-only.
 */
class PackagePolicy
{
    use ChecksOwnership;

    private const NOT_YOURS = 'عفوا، ليس لديك صلاحية التعديل على هذه الباقة.';

    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Packages are on sale, so any authenticated user may read one. */
    public function view(User $user, Package $package): bool
    {
        return true;
    }

    public function create(User $user): Response
    {
        return $user->isSupplier()
            ? Response::allow()
            : $this->denyForbidden('عفوا، ليس لديك صلاحية لإنشاء باقة.');
    }

    public function update(User $user, Package $package): Response
    {
        return $this->owns($user, $package, 'supplier_id')
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    public function delete(User $user, Package $package): Response
    {
        return $this->owns($user, $package, 'supplier_id')
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /** Activating / deactivating is a mutation of the supplier's own offer. */
    public function toggleStatus(User $user, Package $package): Response
    {
        return $this->owns($user, $package, 'supplier_id')
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /** Listing the supplier's products NOT yet in the package. */
    public function viewRemainingProducts(User $user, Package $package): Response
    {
        return $this->owns($user, $package, 'supplier_id')
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /** Buying is the doctor's action. */
    public function buy(User $user, Package $package): Response
    {
        return $user->isDoctor()
            ? Response::allow()
            : $this->denyForbidden('عفوا، ليس لديك صلاحية لشراء الباقة.');
    }
}
