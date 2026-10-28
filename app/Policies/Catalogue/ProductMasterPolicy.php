<?php

namespace App\Policies\Catalogue;

use App\Models\Catalogue\ProductMaster;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use App\Support\RoleCode;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * STAGE 7 — the canonical catalogue is Super Admin territory.
 *
 * This is the first genuine WRITE capability Super Admin has. Stage 4
 * deliberately restricted the role to read-only oversight, on the grounds that
 * granting write access without any admin functionality to attach it to would
 * be inventing business capabilities. Stage 7 supplies exactly that
 * functionality — managing the product catalogue — so the write abilities are
 * added here, narrowly, for this model only.
 *
 * Note what does NOT change: Super Admin still cannot touch orders, payments,
 * inventory or user records. The Gate::before allow-list in AuthServiceProvider
 * remains read-only; catalogue writes are granted by the explicit methods below,
 * not by widening that list.
 *
 * Everyone can READ the catalogue. That is the point of it: suppliers and
 * doctors have to be able to search it in order to select from it, and a
 * canonical product carries no price, no stock and no owner, so there is
 * nothing confidential to withhold.
 */
class ProductMasterPolicy
{
    use ChecksOwnership, HandlesAuthorization;

    /**
     * Anyone authenticated may browse the catalogue in order to select from it.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductMaster $productMaster): bool
    {
        return true;
    }

    /**
     * Only the Super Admin defines what a product IS.
     *
     * This is the rule that stops the catalogue filling with near-duplicate
     * definitions of the same item, one per supplier — which is the state the
     * `products` table was already in before Stage 7.
     */
    public function create(User $user): Response
    {
        return $user->hasRole(RoleCode::SUPER_ADMIN)
            ? Response::allow()
            : Response::deny('عفوا، إدارة المنتجات الرئيسية متاحة للمشرف العام فقط.');
    }

    public function update(User $user, ProductMaster $productMaster): Response
    {
        return $this->create($user);
    }

    /**
     * Withdrawing a product from circulation, rather than deleting it.
     */
    public function archive(User $user, ProductMaster $productMaster): Response
    {
        return $this->create($user);
    }

    /**
     * Deletion is refused for everyone, including the Super Admin.
     *
     * A canonical product is referenced by order history. Stage 5 made those
     * foreign keys RESTRICT, so the database would refuse anyway — this states
     * the rule at the layer the user actually meets, and gives a reason instead
     * of a constraint violation. Use archive() to take a product out of use.
     */
    public function delete(User $user, ProductMaster $productMaster): Response
    {
        return Response::deny(
            'لا يمكن حذف منتج رئيسي لأنه قد يكون مرتبطًا بسجلات طلبات سابقة. يمكنك إلغاء تفعيله بدلاً من ذلك.'
        );
    }
}
