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
        $doctor = User::findOrFail($doctorId);
        return [
            'doctor'   => $doctor,
        ];
    }
}
