<?php

namespace App\Services\Report;

use App\Models\User;
use App\Models\Financial\Order;
use App\Models\Financial\Payment;

class SupplierService
{
    public function getAllDoctors($user, $perPage = 10)
    {
        // 👨‍⚕️ القاعدة الأساسية: كل المستخدمين الذين قسمهم doctor
        $query = User::whereHas('department', function ($q) {
            $q->where('code', 'doctor');
        })->orderByDesc('created_at');

        // 🔍 بحث بالاسم إن وُجد
        if ($search = request()->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // 🤝 فلترة الأطباء الذين تعامل معهم المورد فقط
        if (request()->boolean('interacted_only')) {
            $query->whereHas('orders', function ($orderQuery) use ($user) {
                $orderQuery->whereHas('orderItems.product', function ($productQuery) use ($user) {
                    $productQuery->where('user_id', $user->id);
                });
            });
        }

        // 📄 إرجاع النتائج مع Pagination
        return $query->paginate($perPage);
    }

    public function getDoctorDetails($supplier, $doctorId)
    {
        // التحقق من وجود الطبيب
        $doctor = User::whereHas('department', function ($q) {
            $q->where('code', 'doctor');
        })->findOrFail($doctorId);

        // جلب الطلبات الخاصة بهذا الطبيب التي تحتوي على منتجات تخص المورد الحالي
        $orders = Order::with(['orderItems.product.category'])
            ->where('doctor_id', $doctor->id)
            ->whereHas('orderItems.product', function ($q) use ($supplier) {
                $q->where('user_id', $supplier->id);
            })
            ->orderByDesc('created_at')
            ->get();

        // جلب المدفوعات الخاصة بالطبيب والمورد الحالي فقط
        $payments = Payment::where('doctor_id', $doctor->id)
            ->where('supplier_id', $supplier->id)
            ->where('status', 'confirmed')
            ->orderByDesc('created_at')
            ->get();

        return [
            'doctor'   => $doctor,
            'orders'   => $orders,
            'payments' => $payments,
        ];
    }
}
