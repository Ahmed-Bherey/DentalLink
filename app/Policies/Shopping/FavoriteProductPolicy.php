<?php

namespace App\Policies\Shopping;

use App\Models\Shopping\FavoriteProduct;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * Ownership: favorite_products.doctor_id.
 */
class FavoriteProductPolicy
{
    use ChecksOwnership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, FavoriteProduct $favorite): Response
    {
        return $this->owns($user, $favorite, 'doctor_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }
}
