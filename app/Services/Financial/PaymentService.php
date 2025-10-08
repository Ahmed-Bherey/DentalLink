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
        if ($user->department->code == 'doctor') {
            $baseQuery->where('doctor_id', $user->id);
        } elseif ($user->department->code == 'supplier') {
            $baseQuery->where('supplier_id', $user->id);
        }

        return $baseQuery->where('status', 'confirmed')->paginate($perPage);
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
        $currentStatus = $paymentRecord->status;

        // ✅ الطبيب وافق (سواء تعديل أو حذف)
        if ($data['status'] === 'confirmed') {

            // إذا كانت الحالة الحالية "طلب حذف"
            if ($currentStatus === 'delete_pending') {

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

                // نحفظ نسخة من القيم قبل الحذف للرجوع بها بعد عملية الـ delete
                $deletedPayment = clone $paymentRecord;

                // حذف المدفوعة فعليًا
                $paymentRecord->delete();

                // نعيد النسخة التي كانت قبل الحذف
                return $deletedPayment;
            }

            // إذا كانت الحالة الحالية "تعديل قيد المراجعة"
            if ($currentStatus === 'pending') {
                $paymentRecord->update([
                    'amount' => $paymentRecord->requested_amount,
                    'requested_amount' => null,
                    'status' => 'confirmed',
                ]);

                // تحديث الملخص المالي
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

            // الحالات الأخرى
            $paymentRecord->update(['status' => 'confirmed']);
            return $paymentRecord;
        }

        // ✅ الطبيب رفض (سواء تعديل أو حذف)
        if ($data['status'] === 'rejected') {

            if ($currentStatus === 'delete_pending') {
                // رفض الحذف
                $paymentRecord->update([
                    'status' => 'confirmed',
                ]);
                return $paymentRecord;
            }

            if ($currentStatus === 'pending') {
                // رفض التعديل
                $paymentRecord->update([
                    'requested_amount' => null,
                    'status' => 'confirmed',
                ]);
                return $paymentRecord;
            }

            // رفض عام
            $paymentRecord->update(['status' => 'rejected']);
            return $paymentRecord;
        }

        // حالة غير معروفة
        return $paymentRecord;
    }

    // حذف مدفوعة
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
        if ($user->department->code === 'doctor') {
            $query->where('doctor_id', $user->id);
        } elseif ($user->department->code === 'supplier') {
            $query->where('supplier_id', $user->id);
        }

        // 🔹 فلترة اختيارية بالبحث العام (الاسم)
        if ($search = request()->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('doctor', fn($sub) => $sub->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', fn($sub) => $sub->where('name', 'like', "%{$search}%"));
            });
        }

        // 🔹 فلترة حسب الطبيب ID
        if ($doctorId = request()->get('doctor_id')) {
            $query->where('doctor_id', $doctorId);
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
