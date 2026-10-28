<?php

namespace App\Policies\Financial;

use App\Models\Financial\Cart;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * Ownership: carts.doctor_id. A cart line is strictly private.
 *
 * Denials render 404 so cart-line ids cannot be enumerated.
 */
class CartPolicy
{
    use ChecksOwnership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Cart $cart): Response
    {
        return $this->owns($user, $cart, 'doctor_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Cart $cart): Response
    {
        return $this->owns($user, $cart, 'doctor_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }

    public function delete(User $user, Cart $cart): Response
    {
        return $this->owns($user, $cart, 'doctor_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }
}
