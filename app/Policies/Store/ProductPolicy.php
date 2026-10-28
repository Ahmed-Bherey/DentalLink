<?php

namespace App\Policies\Store;

use App\Models\Store\Product;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * Ownership: products.user_id.
 *
 * The same table holds BOTH supplier catalogue rows and doctor-side inventory
 * (the delivery flow clones a product into the doctor's account), so ownership
 * is per-row and role-independent. That conflation is a known Product Master
 * defect deferred to Stage 7; authorization here follows the schema as it is.
 *
 * Denials render 404, not 403: a non-owner must not be able to distinguish
 * "not yours" from "does not exist", or the endpoint becomes an enumeration
 * oracle over every supplier's catalogue. This preserves the Stage 1 contract.
 */
class ProductPolicy
{
    use ChecksOwnership;

    /** Any authenticated user may list their OWN catalogue (the query scopes it). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * The doctor-facing shopping list of all suppliers' products.
     *
     * Suppliers are excluded: this is the buying view, and Stage 1 already
     * returned 403 for a supplier hitting it.
     */
    public function browseCatalogue(User $user): Response
    {
        return $user->isSupplier()
            ? $this->denyForbidden('عفوا، ليس لديك صلاحية الدخول')
            : Response::allow();
    }

    public function view(User $user, Product $product): Response
    {
        return $this->owns($user, $product, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }

    /**
     * NOTE: no role restriction. Doctors can currently create products, which is
     * how doctor-side inventory works today. Restricting creation to suppliers is
     * a Product Master change (Stage 7), not an authorization fix.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Product $product): Response
    {
        return $this->owns($user, $product, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }

    public function delete(User $user, Product $product): Response
    {
        return $this->owns($user, $product, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }
}
