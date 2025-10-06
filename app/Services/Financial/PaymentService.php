<?php

namespace App\Services\Financial;

use App\Models\Financial\OrderExpense;
use App\Models\Financial\Payment;

class PaymentService
{
    public function index($user, $perPage = 10)
    {
        $baseQuery = Payment::orderBy('created_at', 'desc');

        // fillter by doctor
        if ($user->department == 'doctor') {
            $baseQuery->where('doctor_id', $user->id);
        } elseif ($user->department == 'supplier') {
            $baseQuery->where('supplier_id', $user->id);
        }

        return $baseQuery->paginate($perPage);
    }

    // انشاء مدفوعة
    public function store($user, $data)
    {
        $payment = Payment::create([
            'doctor_id' => $data['doctor_id'],
            'supplier_id' => $user->id,
            'amount' => $data['paid'],
            'date' => $data['date'],
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
            'requested_amount' => $data['paid'],
            'date' => $data['date'],
            'status' => 'pending',
        ]);
        return $paymentRecord;
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

    public function updatePaymentStatus($data, $paymentRecord)
    {
        $amount = $paymentRecord->requested_amount;

        // في حالة رفض تعديل المدفوعة
        if ($data['status'] == 'rejected') {
            $amount = $paymentRecord->amount;
        }

        // ✅ في حالة تأكيد الحذف من الطبيب
        if ($data['status'] == 'delete_approved') {

            // نحصل على آخر سجل مصروفات مرتبط بنفس الطبيب والمورد
            $orderExpense = OrderExpense::where([
                'doctor_id' => $paymentRecord->doctor_id,
                'supplier_id' => $paymentRecord->supplier_id
            ])->latest()->first();

            if ($orderExpense) {
                // تعديل الوضع المالي
                $orderExpense->update([
                    'paid' => $orderExpense->paid - $paymentRecord->amount,
                    'remaining' => $orderExpense->remaining + $paymentRecord->amount,
                ]);
            }

            // حذف المدفوعة فعليًا
            $paymentRecord->delete();

            return response()->json([
                'message' => 'تم حذف المدفوعة ومعالجة الوضع المالي بنجاح'
            ]);
        }

        // ✅ في حالة رفض الحذف
        if ($data['status'] == 'delete_rejected') {
            $paymentRecord->update([
                'status' => 'approved', // أو الحالة السابقة التي كانت عليها قبل طلب الحذف
            ]);

            return $paymentRecord;
        }

        // ✅ في حالة تأكيد/رفض تعديل المدفوعة العادية
        $paymentRecord->update([
            'amount' => $amount,
            'status' => $data['status'],
            'requested_amount' => null,
        ]);

        // تحديث الملخص المالي عند الموافقة على التعديل
        $orderExpense = OrderExpense::where([
            'doctor_id' => $paymentRecord->doctor_id,
            'supplier_id' => $paymentRecord->supplier_id
        ])->latest()->first();

        if ($orderExpense) {
            $total = $orderExpense->paid - $paymentRecord->amount;
            $orderExpense->update([
                'paid' => $orderExpense->paid - $total,
                'remaining' => $orderExpense->remaining + $total,
            ]);
        }

        return $paymentRecord;
    }

    public function requestDelete($user, $paymentRecord)
    {
        $paymentRecord->update([
            'status' => 'delete_pending',
        ]);

        return $paymentRecord;
    }

    // عرض المدفوعات المعلقة للطبيب
    public function pendingPyments($user, $perPage = 10)
    {
        $baseQuery = Payment::where('status', 'pending')->where('doctor_id', $user->id)
            ->orderBy('created_at', 'desc');

        return $baseQuery->paginate($perPage);
    }
}
