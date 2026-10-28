<?php

namespace App\Policies\Financial;

use App\Models\Financial\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * A payment is a TWO-PARTY record with both sides stored directly:
 * payments.supplier_id (who recorded it) and payments.doctor_id (who owes it).
 *
 * The two sides have DIFFERENT rights, and that asymmetry is the whole point:
 *
 *   supplier : records the payment, may amend it, may request its deletion
 *   doctor   : confirms or rejects an amendment or a deletion request
 *
 * A supplier must not be able to self-approve their own amendment, because
 * confirmation is what makes it binding on the doctor.
 */
class PaymentPolicy
{
    use ChecksOwnership;

    private const NOT_YOURS = 'عفوا، ليس لديك صلاحية على هذه المدفوعة.';

    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Either party may read the record. */
    public function view(User $user, Payment $payment): Response
    {
        return $this->isPartyTo($user, $payment)
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /**
     * Only a supplier records a payment. The counterparty must be a doctor —
     * that is enforced by validation on the request, not here, because it is a
     * property of the input rather than of an existing record.
     */
    public function create(User $user): Response
    {
        return $user->isSupplier()
            ? Response::allow()
            : $this->denyForbidden('عفوا، ليس لديك صلاحية لإنشاء مدفوعة.');
    }

    /** Amending the amount is the recording supplier's action. */
    public function update(User $user, Payment $payment): Response
    {
        return $this->owns($user, $payment, 'supplier_id')
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /** Requesting deletion is the recording supplier's action. */
    public function requestDeletion(User $user, Payment $payment): Response
    {
        return $this->owns($user, $payment, 'supplier_id')
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /**
     * Confirming or rejecting is the DOCTOR's decision, and only the doctor
     * named on the payment.
     */
    public function decide(User $user, Payment $payment): Response
    {
        return $this->owns($user, $payment, 'doctor_id')
            ? Response::allow()
            : $this->denyForbidden(self::NOT_YOURS);
    }

    /** The pending-approvals list is the doctor's inbox. */
    public function viewPending(User $user): Response
    {
        return $user->isDoctor()
            ? Response::allow()
            : $this->denyForbidden('عفوا, ليس لديك صلاحية');
    }
}
