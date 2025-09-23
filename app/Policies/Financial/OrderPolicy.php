<?php

namespace App\Policies\Financial;

use App\Models\User;
use App\Models\Financial\Order;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrderPolicy
{
    use HandlesAuthorization;

    /**
     * Create a new policy instance.
     *
     * @return void
     */
    public function __construct() {}

    public function create(User $user)
    {
        return $user->department->code == 'doctor';
    }

    public function update(User $user, Order $order)
    {
        return $order->doctor_id === $user->id;
    }

    public function delete(User $user, Order $order)
    {
        return $order->doctor_id === $user->id;
    }
}
