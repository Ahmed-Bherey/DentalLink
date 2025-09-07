<?php

namespace App\Services\Financial;

use App\Models\Financial\OrderExpense;
use App\Models\Financial\Payment;

class PaymentService
{
    public function index($user)
    {
        $baseQuery = Payment::orderBy('created_at', 'desc');

        // fillter by doctor
        if ($user->department == 'doctor') {
            $baseQuery->where('doctor_id', $user->id);
        } elseif ($user->department == 'supplier') {
            $baseQuery->where('supplier_id', $user->id);
        }

        return $baseQuery->get();
    }

    // انشاء مدفوعة
    public function store($user, $data)
    {
        $payment = Payment::create([
            'doctor_id' => $data['doctor_id'],
            'supplier_id' => $user->id,
            'amount' => $data['amount'],
        ]);

        $orderExpense = OrderExpense::where(['doctor_id' => $payment->doctor_id, 'supplier_id' => $payment->supplier_id])
            ->latest()->first();

        $orderExpense->update([
            'paid' => $orderExpense->paid + $payment->amount,
            'remaining' => $orderExpense->remaining - $payment->amount,
        ]);

        return $payment;
    }

    // تحديث مدفوعة
    public function update($user, $data, $paymentRecord)
    {
        $paymentRecord->update([
            'requested_amount' => $data['amount'],
            'status' => 'pending',
        ]);
        return $paymentRecord;
    }

    // تحديث حالة المدفوعة من جانب الطبيب
    public function updatePaymentStatus($data, $paymentRecord)
    {
        $amount = $paymentRecord->requested_amount;
        if ($data['amount'] == 'rejected') {
            $amount = $paymentRecord->amount;
        }
        $paymentRecord->update([
            'amount' => $amount,
            'status' => $data['status'],
            'requested_amount' => null,
        ]);

        $orderExpense = OrderExpense::where(['doctor_id' => $paymentRecord->doctor_id, 'supplier_id' => $paymentRecord->supplier_id])
            ->latest()->first();
            $total = $orderExpense->paid - $paymentRecord->amount;
            $orderExpense->update([
            'paid' => $orderExpense->paid - $total,
            'remaining' => $orderExpense->remaining + $total,
        ]);
        return $paymentRecord;
    }
}
