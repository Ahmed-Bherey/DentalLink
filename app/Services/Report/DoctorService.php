<?php

namespace App\Services\Report;

use App\Models\User;

class DoctorService
{
    public function getAllsuppliers($user, $perPage = 10)
    {
        // 👨‍⚕️ القاعدة الأساسية: كل المستخدمين الذين قسمهم supplier
        $query = User::whereHas('department', function ($q) {
            $q->where('code', 'supplier');
        })->orderByDesc('created_at');

        // 🔍 بحث بالاسم إن وُجد
        if ($search = request()->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // 🤝 فلترة الموردين الذين تعامل معهم الطبيب فقط
        if (request()->boolean('interacted_only')) {
            $query->whereHas('orders.doctor', function ($orderQuery) use ($user) {
                $orderQuery->where('id', $user->id);
            });
        }

        // 📄 إرجاع النتائج مع Pagination
        return $query->paginate($perPage);
    }
}
