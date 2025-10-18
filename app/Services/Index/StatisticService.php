<?php

namespace App\Services\Index;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Financial\Order;

class StatisticService
{
    public function getDashboardStats($user)
    {
        $query = Order::query()->where('status', 'delivered');

        // فلترة الطلبات بناءً على نوع المستخدم
        if ($user->department?->code === 'doctor') {
            $query->where('doctor_id', $user->id);
        } elseif ($user->department?->code === 'supplier') {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->where('payment_method', 'like', '%مدفوعات%');
        }

        // حساب عدد الطلبات المسلّمة
        $totalDelivered = (clone $query)->count();

        // عدد الطلبات المسلّمة هذا الشهر
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth   = Carbon::now()->endOfMonth();

        $monthlyDelivered = (clone $query)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        // === الآن منطق العلاقات بين الطبيب والمورد ===
        if ($user->department?->code === 'doctor') {
            // عدد الموردين في النظام
            $totalSuppliers = User::whereHas('department', fn($q) => $q->where('code', 'supplier'))->count();

            // الموردين الذين تعامل معهم هذا الطبيب
            $suppliersDealtWith = User::whereHas('department', fn($q) => $q->where('code', 'supplier'))
                ->whereHas('products.orderItems.order', function ($q) use ($user) {
                    $q->where('doctor_id', $user->id);
                })
                ->distinct('id')
                ->count();

            $additionalStats = [
                'total_suppliers' => $totalSuppliers,
                'suppliers_dealt_with' => $suppliersDealtWith,
            ];
        } elseif ($user->department?->code === 'supplier') {
            // عدد الأطباء في النظام
            $totalDoctors = User::whereHas('department', fn($q) => $q->where('code', 'doctor'))->count();

            // الأطباء الذين تعامل معهم هذا المورد
            $doctorsDealtWith = User::whereHas('department', fn($q) => $q->where('code', 'doctor'))
                ->whereHas('orders.orderItems.product', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                ->distinct('id')
                ->count();

            $additionalStats = [
                'total_doctors' => $totalDoctors,
                'doctors_dealt_with' => $doctorsDealtWith,
            ];
        } else {
            // إذا لم يكن طبيبًا ولا موردًا (مدير مثلًا)
            $additionalStats = [
                'total_doctors' => User::whereHas('department', fn($q) => $q->where('code', 'doctor'))->count(),
                'total_suppliers' => User::whereHas('department', fn($q) => $q->where('code', 'supplier'))->count(),
            ];
        }

        // النتيجة النهائية
        return array_merge([
            'total_delivered' => $totalDelivered,
            'monthly_delivered' => $monthlyDelivered,
        ], $additionalStats);
    }
}
