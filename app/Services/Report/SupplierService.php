<?php

namespace App\Services\Report;

use App\Models\User;
use App\Support\RoleCode;

class SupplierService
{
    public function getAllDoctors($user, $perPage = 10)
    {
        // 👨‍⚕️ القاعدة الأساسية: كل المستخدمين الذين قسمهم doctor
        $query = User::withRole(RoleCode::DOCTOR)->orderByDesc('created_at');

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

    /*
     * Stage 4: getDoctorDetails() was removed. Its Stage 1 role check has been
     * replaced by UserPolicy::viewCounterparty, enforced in the controller,
     * which also denies as NOT FOUND so the endpoint cannot be walked to
     * enumerate users of other roles.
     */
}
