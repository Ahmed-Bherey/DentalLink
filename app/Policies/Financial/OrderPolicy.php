<?php

namespace App\Policies\Financial;

use App\Models\Financial\Order;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * An order is a TWO-PARTY record.
 *
 *  - the buying side is orders.doctor_id (a direct column)
 *  - the selling side is indirect: a supplier is a party if they own the product
 *    on at least one order line (order_items -> products.user_id)
 *
 * There is no orders.supplier_id column, and Stage 4 does not add one — the
 * relationship already expresses ownership. (A multi-supplier order is therefore
 * representable, which is a modelling question noted for Stage 5, not something
 * authorization should paper over.)
 *
 * Denials render 403, not 404: both parties already know the order exists, so
 * hiding it would protect nothing.
 */
class OrderPolicy
{
    use ChecksOwnership;

    private const NOT_YOURS = 'عفوا، ليس لديك صلاحية على هذا الطلب.';

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): Response
    {
        return $this->isDoctorParty($user, $order) || $order->isFulfilledBy($user)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /**
     * Only a doctor places orders. This is the buying action, so it is bound to
     * the role rather than to a record.
     */
    public function create(User $user): Response
    {
        return $user->isDoctor()
            ? Response::allow()
            : $this->denyForbidden('عفوا، ليس لديك صلاحية لإنشاء الطلب.');
    }

    /** Editing the order contents is the ordering doctor's action. */
    public function update(User $user, Order $order): Response
    {
        return $this->isDoctorParty($user, $order)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /**
     * "Delete" is really "request deletion" — the doctor flags the order and the
     * supplier approves. The request itself is the doctor's.
     */
    public function delete(User $user, Order $order): Response
    {
        return $this->isDoctorParty($user, $order)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /**
     * Driving the order status (preparing, delivered, …) is the SUPPLIER's
     * action, and only a supplier who actually owns a line in the order.
     *
     * A doctor must not be able to mark their own order delivered — that was the
     * original self-crediting exploit.
     */
    public function fulfil(User $user, Order $order): Response
    {
        return $order->isFulfilledBy($user)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    private function isDoctorParty(User $user, Order $order): bool
    {
        return $this->owns($user, $order, 'doctor_id');
    }
}
