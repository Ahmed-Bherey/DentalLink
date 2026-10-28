<?php

namespace App\Services\Financial;

use App\Exceptions\Financial\PaymentException;
use App\Models\FcmToken;
use App\Models\Financial\Payment;
use App\Services\Notifaction\FirebaseService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /*
     * Stage 4: the interim guards that lived here (guardSupplierParty /
     * guardDoctorParty) have been replaced by PaymentPolicy, invoked from the
     * controller. Authorization is no longer a service concern.
     *
     * Stage 6: every balance mutation below now runs inside a transaction that
     * begins by taking a row lock on the ledger via LedgerService::lockFor().
     * Payment rows are never deleted and `paid` is never incremented by hand —
     * it is recomputed from the payment rows themselves. See LedgerService.
     */

    public function __construct(private LedgerService $ledger) {}

    public function index($user, $perPage = 10)
    {
        $baseQuery = Payment::orderBy('created_at', 'desc');

        // fillter by doctor
        if ($user->isDoctor()) {
            $baseQuery->where('doctor_id', $user->id);
        } elseif ($user->isSupplier()) {
            $baseQuery->where('supplier_id', $user->id);
        }

        return $baseQuery->where('status', 'confirmed')->paginate($perPage);
    }

    /**
     * Record a payment from a supplier against a doctor's outstanding balance.
     *
     * Four defects are fixed here relative to the previous implementation:
     *
     *  1. The ledger was looked up by `$user->supplier_id`, an attribute that
     *     does not exist on User — so it was always NULL and the lookup always
     *     missed. (Characterised by FinancialBaselineTest.)
     *
     *  2. The overpayment guard read `paid > remaining + paid`, which reduces to
     *     `0 > remaining`. It could only fire on an already-negative balance, so
     *     in practice no payment was ever refused.
     *
     *  3. There was no transaction. The payment row was inserted, then the
     *     ledger updated in a separate statement — a failure between the two
     *     left a payment that no balance reflected.
     *
     *  4. There was no lock, so two concurrent payments could both read the same
     *     remaining balance and both be accepted.
     *
     * The overpayment rule itself is not invented: `update()` already enforced
     * exactly this, correctly. `store()` was evidently meant to do the same and
     * the expression was simply wrong.
     */
    public function store($user, $data)
    {
        $amount = Money::of($data['paid']);

        if (! Money::isPositive($amount)) {
            throw PaymentException::notPositive();
        }

        [$payment, $doctorId] = DB::transaction(function () use ($user, $data, $amount) {
            // Lock first, then read. Everything below observes a balance no
            // other request can change until this transaction commits.
            $ledger = $this->ledger->lockFor((int) $data['doctor_id'], (int) $user->id);

            $available = $this->ledger->roomForPayment($ledger);

            if (Money::isZero($available)) {
                throw PaymentException::nothingOutstanding();
            }

            if (Money::greaterThan($amount, $available)) {
                throw PaymentException::overpayment($amount, $available);
            }

            $payment = Payment::create([
                'doctor_id' => $data['doctor_id'],
                'supplier_id' => $user->id,
                'amount' => $amount,
                'date' => $data['date'],
                'status' => 'confirmed',
            ]);

            // Recompute rather than increment: `paid` becomes the sum of this
            // pair's confirmed payments, so it cannot drift from them.
            $this->ledger->recalculate($ledger);

            $payment->notificationsCenters()->create([
                'user_id' => $payment->doctor_id,
                'title' => 'مدفوعة جديدة',
                'message' => '💰 تم إنشاء مدفوعة جديدة!<br>'
                    ."🔹 رقم المدفوعة: #{$payment->id}<br>"
                    .'💵 المبلغ: '.Money::format($payment->amount),
                'type' => 'dollar',
                'color' => 'green',
            ]);

            return [$payment, $payment->doctor_id];
        });

        // Push notifications are deliberately OUTSIDE the transaction. An
        // unreachable Firebase must not roll back a recorded payment, and a
        // transaction should not be held open across a network call.
        $this->notifyDoctor(
            $doctorId,
            'مدفوعة جديدة 💰',
            'تم إنشاء مدفوعة جديدة برقم #'.$payment->id
        );

        return $payment;
    }

    /**
     * Send a push notification, tolerating failure.
     *
     * Extracted so the transactional methods above stay readable, and so the
     * "notification failure must not undo money" rule is stated in one place.
     */
    private function notifyDoctor($doctorId, string $title, string $body): void
    {
        try {
            $tokens = FcmToken::where('user_id', $doctorId)->pluck('fcm_token');
            $firebase = new FirebaseService;

            foreach ($tokens as $token) {
                $firebase->send($title, $body, $token, '/operations/current-payments');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * A supplier proposes a new amount for an existing payment.
     *
     * This does NOT move money. It parks the proposal in `requested_amount` and
     * flips the payment to `pending`; the doctor decides in updatePaymentStatus().
     * `amount` — and therefore the ledger — is untouched until then.
     *
     * The overpayment rule was already correct here and is preserved. What
     * changes: it now runs under the ledger lock, compares exactly rather than
     * with float operators, and measures room with this payment's own current
     * contribution released, via LedgerService::roomForPayment().
     *
     * The `(int)` casts in the notification are also gone — they truncated
     * 150.75 to "150.00" in the message shown to the doctor being asked to
     * approve it.
     */
    public function update($user, $data, $paymentRecord)
    {
        $requested = Money::of($data['paid']);

        if (! Money::isPositive($requested)) {
            throw PaymentException::notPositive();
        }

        // Only a settled payment can be amended. Allowing an amendment on top of
        // a payment already awaiting a decision would let a supplier overwrite a
        // proposal the doctor is in the middle of considering.
        if ($paymentRecord->status !== 'confirmed') {
            throw PaymentException::notAmendable($paymentRecord->status);
        }

        return DB::transaction(function () use ($user, $data, $paymentRecord, $requested) {
            $ledger = $this->ledger->lockFor(
                (int) $paymentRecord->doctor_id,
                (int) $paymentRecord->supplier_id
            );

            $available = $this->ledger->roomForPayment($ledger, (int) $paymentRecord->id);

            if (Money::greaterThan($requested, $available)) {
                throw PaymentException::overpayment($requested, $available);
            }

            $previousAmount = Money::of($paymentRecord->amount);

            $paymentRecord->update([
                'requested_amount' => $requested,
                'date' => $data['date'],
                'status' => 'pending',
            ]);

            $paymentRecord->notificationsCenters()->create([
                'user_id' => $paymentRecord->doctor_id,
                'title' => 'تعديل على المدفوعة',
                'message' => "✏️ قام المورد {$user->name} بتعديل المدفوعة.<br>"
                    ."🧾 رقم المدفوعة: #{$paymentRecord->id}<br>"
                    .'💰 المبلغ السابق: '.Money::format($previousAmount).'<br>'
                    .'💵 المبلغ المطلوب الآن: '.Money::format($requested),
                'type' => 'dollar',
                'color' => 'green',
            ]);

            return $paymentRecord;
        });
    }

    // تحديث حالة المدفوعة من جانب الطبيب
    // public function updatePaymentStatus($data, $paymentRecord)
    // {
    //     $amount = $paymentRecord->requested_amount;
    //     if ($data['status'] == 'rejected') {
    //         $amount = $paymentRecord->amount;
    //     }
    //     $paymentRecord->update([
    //         'amount' => $amount,
    //         'status' => $data['status'],
    //         'requested_amount' => null,
    //     ]);

    //     $orderExpense = OrderExpense::where(['doctor_id' => $paymentRecord->doctor_id, 'supplier_id' => $paymentRecord->supplier_id])
    //         ->latest()->first();
    //     $total = $orderExpense->paid - $paymentRecord->amount;
    //     $orderExpense->update([
    //         'paid' => $orderExpense->paid - $total,
    //         'remaining' => $orderExpense->remaining + $total,
    //     ]);
    //     return $paymentRecord;
    // }

    /**
     * The doctor decides on a supplier's pending request (an amendment or a void).
     *
     * This method previously contained the two most damaging financial defects
     * in the application, both characterised by FinancialBaselineTest:
     *
     *  1. WRONG SIGN. Both confirm branches wrote
     *         remaining = total + paid
     *     instead of total - paid. Approving anything therefore INCREASED the
     *     amount the doctor appeared to owe, by twice the amount already paid.
     *
     *  2. PAID OVERWRITTEN. The amendment branch computed
     *         $total = paid - amount;  paid = paid - $total;
     *     which reduces to `paid = amount` — the ledger's accumulated paid total
     *     was replaced by the single amended payment, discarding every other
     *     payment the doctor had ever made to that supplier.
     *
     *  3. HARD DELETE. Approving a void called delete(), destroying the record
     *     of a payment that had really been made.
     *
     * Neither arithmetic bug can recur in this implementation, because `paid`
     * and `remaining` are no longer written by this method at all. It changes
     * the payment's own state and then asks LedgerService to re-derive the
     * balance from the payment rows.
     */
    public function updatePaymentStatus($data, $paymentRecord, $user = null)
    {
        $currentStatus = $paymentRecord->status;
        $decision = $data['status'];

        // Only these two states carry an outstanding request to decide on.
        if (! in_array($currentStatus, ['pending', 'delete_pending'], true)) {
            throw PaymentException::notDecidable($currentStatus);
        }

        return DB::transaction(function () use ($decision, $currentStatus, $paymentRecord) {
            $ledger = $this->ledger->lockFor(
                (int) $paymentRecord->doctor_id,
                (int) $paymentRecord->supplier_id
            );

            if ($decision === 'confirmed') {
                if ($currentStatus === 'delete_pending') {
                    // VOID, not delete. The row survives with its amount intact
                    // so the history stays auditable; LedgerService counts only
                    // `confirmed` payments, so it stops contributing to `paid`
                    // the moment the status changes. Existing listings already
                    // filter to `confirmed`, so it disappears from the UI just
                    // as a deleted row did.
                    $paymentRecord->update([
                        'status' => 'voided',
                        'requested_amount' => null,
                    ]);
                } else {
                    // Amendment approved: the proposal becomes the real amount.
                    $paymentRecord->update([
                        'amount' => Money::of($paymentRecord->requested_amount),
                        'requested_amount' => null,
                        'status' => 'confirmed',
                    ]);
                }
            } elseif ($decision === 'rejected') {
                // Both rejections return the payment to its settled state at its
                // original amount. The proposal is discarded; no money moves.
                $paymentRecord->update([
                    'status' => 'confirmed',
                    'requested_amount' => null,
                ]);
            } else {
                throw PaymentException::notDecidable($currentStatus);
            }

            $this->ledger->recalculate($ledger);

            return $paymentRecord->refresh();
        });
    }

    // حذف مدفوعة
    public function requestDelete($user, $paymentRecord)
    {
        $paymentRecord->update([
            'status' => 'delete_pending',
        ]);

        $paymentRecord->notificationsCenters()->create([
            'user_id' => $paymentRecord->doctor_id,
            'title' => 'طلب حذف مدفوعة',
            'message' => "⚠️ قام المورد {$user->name} بطلب حذف المدفوعة.<br>"
                ."🧾 رقم المدفوعة: #{$paymentRecord->id}<br>"
                .'⏳ الحالة: بانتظار تأكيدك',
            'type' => 'dollar',
            'color' => 'green',
        ]);

        // $tokens = FcmToken::where('user_id', $paymentRecord->doctor_id)->pluck('fcm_token');
        // $firebase = new FirebaseService();
        // foreach ($tokens as $token) {
        //     $firebase->send(
        //         'طلب حذف مدفوعة',
        //         'قام المورد ' . $user->name . ' بطلب حذف المدفوعة رقم #' . $paymentRecord->id . '، وهي بانتظار تأكيدك.',
        //         $token,
        //         '/operations/current-payments'
        //     );
        // }

        return $paymentRecord;
    }

    // عرض المدفوعات المعلقة للطبيب
    public function pendingPyments($user, $perPage = 10)
    {
        $status = ['pending', 'delete_pending'];
        $baseQuery = Payment::where('doctor_id', $user->id)
            ->whereIn('status', $status)
            ->orderBy('created_at', 'desc');

        return $baseQuery->paginate($perPage);
    }

    // البحث والفلاتر
    public function search($user, $perPage = 10)
    {
        $query = Payment::with(['doctor', 'supplier'])
            ->orderBy('created_at', 'desc')
            ->where('status', 'confirmed');

        // 🔹 فلترة حسب نوع المستخدم
        if ($user->isDoctor()) {
            $query->where('doctor_id', $user->id);
        } elseif ($user->isSupplier()) {
            $query->where('supplier_id', $user->id);
        }

        // 🔹 فلترة اختيارية بالبحث العام (الاسم)
        if ($search = request()->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('doctor', fn ($sub) => $sub->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($sub) => $sub->where('name', 'like', "%{$search}%"));
            });
        }

        // 🔹 فلترة حسب الطبيب ID
        if ($doctorId = request()->get('doctor_id')) {
            $query->where('doctor_id', $doctorId);
        }

        // 🔹 فلترة حسب المورد ID
        if ($supplierId = request()->get('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }

        // 🔹 فلترة حسب التاريخ
        if ($from = request()->get('from_date')) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to = request()->get('to_date')) {
            $query->whereDate('date', '<=', $to);
        }

        return $query->paginate($perPage);
    }
}
