<?php

namespace App\Services\Report;

use App\Models\User;

class SupplierService
{
    public function getAllDoctors($user, $perPage = 10)
    {
        // قاعدة الاستعلام الأساسية: كل الأطباء
        $query = User::whereHas('department', function ($q) {
            $q->where('code', 'doctor');
        })->orderByDesc('created_at');

        // 🔹 لو فيه بحث من الفرونت
        if ($search = request()->get('search')) {
            // فقط الأطباء الذين تعاملوا مع المورد الحالي
            $query->whereHas('orders', function ($orderQuery) use ($user) {
                $orderQuery->whereHas('orderItems.product', function ($productQuery) use ($user) {
                    $productQuery->where('user_id', $user->id);
                });
            })->where('name', 'like', "%{$search}%"); // بحث بالاسم أيضًا
        }

        // 🔹 إرجاع النتائج مع Pagination
        return $query->paginate($perPage);
    }
}
