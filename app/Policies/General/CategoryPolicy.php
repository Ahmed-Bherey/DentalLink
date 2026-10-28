<?php

namespace App\Policies\General;

use App\Models\General\Category;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * Ownership: categories.user_id (the creator).
 *
 * Categories are SHARED reference data: the index is global and every user can
 * read any category, because products from all suppliers are grouped by them.
 * Only the creator may modify or delete their own.
 *
 * NOTE: categories being user-created shared data is a modelling problem
 * (two suppliers can create duplicate "Gloves" categories). That belongs to
 * Stage 5 / Stage 7, not to authorization.
 */
class CategoryPolicy
{
    use ChecksOwnership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Shared reference data: readable by any authenticated user. */
    public function view(User $user, Category $category): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Category $category): Response
    {
        return $this->owns($user, $category, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }

    public function delete(User $user, Category $category): Response
    {
        return $this->owns($user, $category, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }
}
