<?php

namespace App\Services\Financial;

use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Financial\OrderItemRefund;
use App\Models\Financial\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single authoritative definition of what a doctor owes a supplier.
 *
 * WHY THIS EXISTS
 *
 * Before Stage 6 the answer to "what is remaining?" depended on who you asked:
 *
 *   OrderService        wrote  remaining = remaining + charge
 *   PaymentService      wrote  remaining = remaining - payment      (one path)
 *   PaymentService      wrote  remaining = total + paid             (two paths)
 *   DoctorResource      showed remaining = total - paid, with the stored
 *   SupplierResource           column commented out beside it
 *   PaymentResource
 *
 * Three different formulas for one number, and the API layer had already
 * stopped trusting the stored column — the commented-out code in those
 * resources is a developer working around a balance they knew was wrong.
 *
 * THE MODEL
 *
 * `order_expenses` is a materialised running balance per (doctor, supplier)
 * pair — Stage 5 made that pair unique. Two facts feed it:
 *
 *   total   accumulates as orders are delivered, and decreases on returns.
 *           OrderService owns this. It is genuinely a stored total: there is no
 *           per-order record of what was charged, so it cannot be re-derived.
 *
 *   paid    is that pair's standing payments MINUS their approved refunds.
 *           Unlike total, it IS re-derivable, because both are rows.
 *
 * So `paid` is never incremented here. It is recomputed from the payments and
 * refunds tables on every change, and `remaining` is recomputed from
 * `total - paid`. Drift between the ledger and the events that built it is
 * therefore not something that has to be prevented by careful bookkeeping — it
 * is arithmetically impossible, because the ledger is a projection of those rows.
 *
 * Stage 7 added the refund half. An approved return reduces the charge AND the
 * effective paid amount by the same figure, priced from the order line's frozen
 * snapshot. See effectivePaid() and recordRefund().
 *
 * This is not a new ledger domain. It is the existing OrderExpense model with
 * one correct definition instead of five conflicting ones.
 */
class LedgerService
{
    /**
     * Payment states in which the money counts as received.
     *
     * `pending` and `delete_pending` are included, which is not obvious. Both
     * mean a supplier has REQUESTED a change to a payment that was already
     * settled — an amended amount, or a reversal — and the doctor has not
     * decided yet. Crucially, `amount` is untouched while a request is open;
     * the proposal lives separately in `requested_amount`. The money really was
     * received, so it must keep counting until the doctor actually decides.
     *
     * Excluding them would mean merely ASKING to amend a payment instantly
     * removed it from the balance, inflating what the doctor appears to owe
     * before anyone agreed to anything.
     *
     * `rejected` and `voided` are excluded: in both the payment does not stand.
     */
    public const SETTLED_STATUSES = ['confirmed', 'pending', 'delete_pending'];

    /**
     * Fetch the ledger row for a pair and lock it for the rest of the transaction.
     *
     * Every balance mutation must go through here. The lock is what makes
     * check-then-write safe: without it, two concurrent payments both read the
     * same remaining balance, both decide they fit, and both commit — producing
     * a negative balance that no single request would ever have allowed.
     *
     * Creates the row if the pair has no ledger yet, so a payment can be
     * recorded before any order has been delivered. `firstOrCreate` is safe
     * against the concurrent-insert race because Stage 5 added the unique index
     * on (doctor_id, supplier_id) — the loser of that race gets a constraint
     * violation rather than a duplicate ledger.
     *
     * MUST be called inside a transaction; a lock outside one is released
     * immediately and buys nothing.
     */
    public function lockFor(int $doctorId, int $supplierId): OrderExpense
    {
        $existing = OrderExpense::where('doctor_id', $doctorId)
            ->where('supplier_id', $supplierId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $created = OrderExpense::create([
            'doctor_id' => $doctorId,
            'supplier_id' => $supplierId,
            'total' => Money::zero(),
            'paid' => Money::zero(),
            'remaining' => Money::zero(),
        ]);

        // Re-read under the lock. The row we just inserted is ours, but taking
        // the lock explicitly keeps every caller on the same footing.
        return OrderExpense::whereKey($created->getKey())->lockForUpdate()->first();
    }

    /**
     * The authoritative paid amount: the sum of settled payments for this pair.
     *
     * @param  int|null  $excludingPaymentId  Ignore one payment — used when
     *                                        evaluating a change to that payment,
     *                                        so its own current amount does not
     *                                        count against the room available.
     */
    public function settledPaid(int $doctorId, int $supplierId, ?int $excludingPaymentId = null): string
    {
        return Money::of(
            $this->settledPaymentsQuery($doctorId, $supplierId, $excludingPaymentId)->sum('amount')
        );
    }

    private function settledPaymentsQuery(int $doctorId, int $supplierId, ?int $excludingPaymentId = null): Builder
    {
        return Payment::query()
            ->where('doctor_id', $doctorId)
            ->where('supplier_id', $supplierId)
            ->whereIn('status', self::SETTLED_STATUSES)
            ->when($excludingPaymentId !== null, fn ($q) => $q->whereKeyNot($excludingPaymentId));
    }

    /**
     * Total value of approved returns between this pair (Stage 7).
     *
     * Every row here represents money the doctor has been given back, priced
     * from the order line's frozen snapshot rather than the product's current
     * price.
     */
    public function refundedTotal(int $doctorId, int $supplierId): string
    {
        return Money::of(
            OrderItemRefund::query()
                ->where('doctor_id', $doctorId)
                ->where('supplier_id', $supplierId)
                ->sum('amount')
        );
    }

    /**
     * The authoritative EFFECTIVE paid amount: payments received, less refunds
     * given back.
     *
     * Stage 7 business rule — when a supplier approves a return, the doctor is
     * considered to have received the corresponding money, so it no longer
     * counts as paid. The charge is reduced by the same amount, so both sides of
     * the balance move together:
     *
     *     obligation 1000, paid 1000, refund 300  ->  total 700, paid 700, remaining 0
     *     obligation  100, paid   60, refund  30  ->  total  70, paid  30, remaining 40
     *
     * `remaining` is therefore unchanged by a refund. That is not a special case
     * — it falls out of (total - R) - (paid - R).
     *
     * Floored at zero for the case where refunds exceed payments, which happens
     * when goods are returned before being paid for. There the return simply
     * cancels the obligation and no money changes hands; nothing was paid, so
     * nothing can be un-paid. If refunds exceed payments AND the goods were paid
     * for, the doctor is in credit — an open business question carried over from
     * Stage 6 and recorded in the Stage 7 report, not decided here.
     */
    public function effectivePaid(int $doctorId, int $supplierId): string
    {
        return Money::atLeastZero(Money::sub(
            $this->settledPaid($doctorId, $supplierId),
            $this->refundedTotal($doctorId, $supplierId)
        ));
    }

    /**
     * Recompute `paid` and `remaining` from the underlying facts and persist.
     *
     * Call after ANY change to a payment belonging to this pair. Because paid is
     * derived rather than adjusted, this is idempotent: running it twice gives
     * the same answer, and a missed call is visible as a discrepancy rather than
     * silently corrupting the balance.
     *
     * `remaining` is clamped at zero only as a display floor. If it would have
     * gone negative that means paid exceeds total, which the payment validation
     * is supposed to prevent — see outstanding() for how that is surfaced.
     */
    public function recalculate(OrderExpense $ledger): OrderExpense
    {
        return $this->persist($ledger, Money::of($ledger->total));
    }

    /**
     * Write total, paid and remaining together, in one statement.
     *
     * Deliberately a single save. Writing the total in one statement and then
     * the derived columns in another leaves a window — however brief, and
     * visible to anything reading outside this transaction — in which
     * `remaining != total - paid`. One write means the row is never observed
     * in a state that violates the invariant.
     */
    private function persist(OrderExpense $ledger, string $total): OrderExpense
    {
        // Stage 7: effective paid, i.e. payments net of approved refunds.
        $paid = $this->effectivePaid((int) $ledger->doctor_id, (int) $ledger->supplier_id);

        $ledger->forceFill([
            'total' => $total,
            'paid' => $paid,
            // Floored at zero for display. If paid exceeds total the doctor is
            // in credit — reachable when a return reduces the total below what
            // has already been paid. How credit should be represented is an open
            // business question, so this does not invent an answer; it shows
            // nothing outstanding and leaves the overpayment visible in `paid`.
            'remaining' => Money::atLeastZero(Money::sub($total, $paid)),
        ])->save();

        return $ledger;
    }

    /**
     * What is still owed: total - paid, floored at zero.
     *
     * This is THE definition. Resources, services and reports must all call it
     * rather than recomputing the subtraction themselves.
     */
    public function outstanding(?OrderExpense $ledger): string
    {
        if ($ledger === null) {
            return Money::zero();
        }

        return Money::atLeastZero(Money::sub(Money::of($ledger->total), Money::of($ledger->paid)));
    }

    /**
     * How much room a new or amended payment has.
     *
     * For an amendment, the payment's own current contribution is released first
     * — otherwise raising a payment from 100 to 120 would be measured against a
     * balance that still counts the original 100.
     */
    public function roomForPayment(OrderExpense $ledger, ?int $excludingPaymentId = null): string
    {
        $paidByOthers = $this->settledPaid(
            (int) $ledger->doctor_id,
            (int) $ledger->supplier_id,
            $excludingPaymentId
        );

        return Money::atLeastZero(Money::sub(Money::of($ledger->total), $paidByOthers));
    }

    /**
     * Add a charge to the running total (an order was delivered).
     *
     * Assumes the caller holds the lock from lockFor().
     */
    public function addCharge(OrderExpense $ledger, $amount): OrderExpense
    {
        return $this->persist($ledger, Money::add($ledger->total, $amount));
    }

    /**
     * Reduce the running total (an order or line was returned).
     *
     * The total is floored at zero because refunding more than was ever charged
     * is meaningless. `remaining` is NOT independently clamped — it is always
     * recomputed from total and paid, which is what previously allowed the two
     * columns to drift apart into a state where remaining != total - paid.
     */
    public function reduceCharge(OrderExpense $ledger, $amount): OrderExpense
    {
        return $this->persist($ledger, Money::atLeastZero(Money::sub($ledger->total, $amount)));
    }

    /**
     * Record an approved return and apply both of its financial effects at once.
     *
     * The two effects — a refund row (which reduces effective paid) and a
     * reduction of the charge (which reduces the obligation) — MUST happen
     * together. Doing one without the other silently breaks the balance, so this
     * method is the only supported way to perform either, and it is not possible
     * to call half of it.
     *
     * The price comes from the order line's frozen snapshot, passed in by the
     * caller. This method never reads a product price.
     *
     * Assumes the caller holds the ledger lock and is inside a transaction.
     */
    public function recordRefund(
        OrderExpense $ledger,
        OrderItem $orderItem,
        int $quantity,
        $unitPrice,
        ?int $approvedBy = null
    ): OrderItemRefund {
        $amount = Money::mul($unitPrice, $quantity);

        $refund = OrderItemRefund::create([
            'order_item_id' => $orderItem->id,
            'doctor_id' => $ledger->doctor_id,
            'supplier_id' => $ledger->supplier_id,
            'quantity' => $quantity,
            'unit_price' => Money::of($unitPrice),
            'amount' => $amount,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        // Reduce the obligation by the same amount. persist() then re-derives
        // paid, which now sees the refund row created a moment ago — so both
        // sides of the balance move in a single write.
        $this->reduceCharge($ledger, $amount);

        return $refund;
    }

    /**
     * Does this ledger row satisfy the Stage 6 invariants?
     *
     * Used by tests and available for a future reconciliation command. Returns
     * the list of broken invariants, empty when the row is sound.
     *
     * @return string[]
     */
    public function invariantViolations(OrderExpense $ledger): array
    {
        $violations = [];

        $total = Money::of($ledger->total);
        $paid = Money::of($ledger->paid);
        $remaining = Money::of($ledger->remaining);
        $settled = $this->effectivePaid((int) $ledger->doctor_id, (int) $ledger->supplier_id);

        if (Money::isNegative($total)) {
            $violations[] = "total is negative ({$total})";
        }

        if (Money::isNegative($paid)) {
            $violations[] = "paid is negative ({$paid})";
        }

        if (Money::isNegative($remaining)) {
            $violations[] = "remaining is negative ({$remaining})";
        }

        if (Money::greaterThan($paid, $total)) {
            $violations[] = "paid ({$paid}) exceeds total ({$total})";
        }

        if (Money::cmp($remaining, Money::atLeastZero(Money::sub($total, $paid))) !== 0) {
            $violations[] = "remaining ({$remaining}) != total - paid (".Money::sub($total, $paid).')';
        }

        if (Money::cmp($paid, $settled) !== 0) {
            $violations[] = "stored paid ({$paid}) != payments less approved refunds ({$settled})";
        }

        return $violations;
    }
}
