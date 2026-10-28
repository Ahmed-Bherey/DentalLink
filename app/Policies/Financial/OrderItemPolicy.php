<?php

namespace App\Policies\Financial;

use App\Models\Financial\OrderItem;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * An order line has NO ownership column. Both sides are reached by relationship:
 *
 *   buying side  : order_items -> orders.doctor_id
 *   selling side : order_items -> products.user_id
 *
 * Stage 4 follows those relationships rather than denormalising an owner column
 * onto the table (that would be a schema change, and it is not needed).
 *
 * FAIL CLOSED: if the parent order or the product is missing — both foreign keys
 * are nullable, a known Stage 5 integrity issue — the line has no determinable
 * owner and every action is denied.
 */
class OrderItemPolicy
{
    use ChecksOwnership;

    private const NOT_YOURS = 'عفوا، ليس لديك صلاحية على هذا المنتج.';

    public function view(User $user, OrderItem $item): Response
    {
        return $this->belongsToDoctor($user, $item) || $item->isSuppliedBy($user)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /** Changing a line quantity changes what the order is worth: doctor only. */
    public function update(User $user, OrderItem $item): Response
    {
        return $this->belongsToDoctor($user, $item)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /** Requesting a return / removal of a line is the ordering doctor's action. */
    public function requestReturn(User $user, OrderItem $item): Response
    {
        return $this->belongsToDoctor($user, $item)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /**
     * Approving or rejecting a return moves stock and rewrites the ledger, so it
     * belongs to the supplier that owns the product on the line.
     */
    public function decideReturn(User $user, OrderItem $item): Response
    {
        return $item->isSuppliedBy($user)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    private function belongsToDoctor(User $user, OrderItem $item): bool
    {
        $order = $item->order;

        return $order !== null && $this->owns($user, $order, 'doctor_id');
    }
}
