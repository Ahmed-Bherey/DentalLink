<?php

namespace App\Policies\Financial;

use App\Models\Financial\OrderExpense;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * The running balance between one doctor and one supplier.
 *
 * IMPORTANT: this resource currently has NO direct HTTP surface. The endpoint
 * that exposed it (GET /api/payment/updateExpen, which zeroed a balance) was
 * deleted in Stage 1. Today the table is only mutated as a side effect of
 * delivering an order or recording a payment.
 *
 * The policy exists so the rule is already defined and tested when Stage 6
 * exposes the ledger properly. It is registered and enforced by
 * OrderExpense::visibleTo() so it cannot silently rot.
 *
 * No `update`/`delete` abilities are defined: nothing may mutate a balance
 * directly. Balances change only through the order and payment flows.
 */
class OrderExpensePolicy
{
    use ChecksOwnership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Only the two named parties may read a balance. */
    public function view(User $user, OrderExpense $expense): Response
    {
        return $this->isPartyTo($user, $expense)
            ? Response::allow()
            : $this->denyForbidden('عفوا، ليس لديك صلاحية على هذا الحساب.');
    }
}
