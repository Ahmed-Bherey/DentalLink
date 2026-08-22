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

    /**
     * Security (Stage 1): the target must actually be a doctor.
     *
     * This previously did a bare User::findOrFail($id), so any authenticated caller
     * could read ANY user record — other suppliers, and any future privileged
     * account — by walking ids. The resource exposes name, phone, phone2, address
     * and city, so it was a full directory disclosure.
     *
     * Narrowing further (to counterparties the caller has actually transacted with)
     * needs the role/relationship model from Stage 3/4; this containment stops
     * arbitrary cross-role enumeration now.
     */
    public function getDoctorDetails($supplier, $doctorId)
    {
        $doctor = User::whereHas('department', function ($q) {
            $q->where('code', 'doctor');
        })->findOrFail($doctorId);

        return [
            'doctor'   => $doctor,
        ];
    }
}
