<?php

namespace App\Services\Index;

use App\Models\Financial\Order;
use App\Models\Store\Product;
use App\Models\User;
use App\Support\RoleCode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;

class StatisticService
{
    public function getDashboardStats($user)
    {
        $query = Order::query()->where('status', 'delivered');

        // 🔹 فلترة الطلبات بناءً على نوع المستخدم
        if ($user->isDoctor()) {
            $query->where('doctor_id', $user->id);
        } elseif ($user->isSupplier()) {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->where('payment_method', 'like', '%مدفوعات%');
        }

        // 🔹 عدد الطلبات المسلّمة
        $totalDelivered = (clone $query)->count();

        // 🔹 عدد الطلبات المسلّمة هذا الشهر
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $monthlyDelivered = (clone $query)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        // 🔹 إحصائيات إضافية حسب نوع المستخدم
        if ($user->isDoctor()) {
            // عدد الموردين في النظام
            $totalUsers = User::withRole(RoleCode::SUPPLIER)->count();

            // الموردين الذين تعامل معهم الطبيب
            $usersDealtWith = User::withRole(RoleCode::SUPPLIER)
                ->whereHas('products.orderItems.order', function ($q) use ($user) {
                    $q->where('doctor_id', $user->id);
                })
                ->distinct('id')
                ->count();

            $topProductsQuery = Product::select('products.id', 'products.name', 'products.img')
                ->selectRaw('SUM(order_items.quantity) as total_sold')
                ->join('order_items', 'products.id', '=', 'order_items.product_id')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'delivered')
                ->where('orders.doctor_id', $user->id)
                ->groupBy('products.id', 'products.name', 'products.img')
                ->orderByDesc('total_sold');
        } elseif ($user->isSupplier()) {
            // عدد الأطباء في النظام
            $totalUsers = User::withRole(RoleCode::DOCTOR)->count();

            // الأطباء الذين تعامل معهم المورد
            $usersDealtWith = User::withRole(RoleCode::DOCTOR)
                ->whereHas('orders.orderItems.product', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                ->distinct('id')
                ->count();

            $topProductsQuery = Product::select('products.id', 'products.name', 'products.img')
                ->selectRaw('SUM(order_items.quantity) as total_sold')
                ->join('order_items', 'products.id', '=', 'order_items.product_id')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'delivered')
                ->where('products.user_id', $user->id)
                ->groupBy('products.id', 'products.name', 'products.img')
                ->orderByDesc('total_sold');
        } else {
            /*
             * Platform-wide figures. Reachable ONLY by a Super Admin: the
             * `view-dashboard` gate denies anyone without a known role, and
             * doctors/suppliers are handled above. Fail closed if that ever
             * changes, rather than silently exposing every tenant's totals.
             */
            if (! $user->isSuperAdmin()) {
                throw new AuthorizationException('عفوا، ليس لديك صلاحية الاطلاع على الإحصائيات.');
            }

            $totalUsers = User::count();
            $usersDealtWith = null; // لا ينطبق

            $topProductsQuery = Product::select('products.id', 'products.name', 'products.img')
                ->selectRaw('SUM(order_items.quantity) as total_sold')
                ->join('order_items', 'products.id', '=', 'order_items.product_id')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'delivered')
                ->groupBy('products.id', 'products.name', 'products.img')
                ->orderByDesc('total_sold');
        }

        // ✅ اجلب النتائج فعلاً قبل الحساب
        $topProductsResults = (clone $topProductsQuery)->get();

        // 🔹 إجمالي الكمية المباعة (من النتائج نفسها)
        $totalQuantitySold = $topProductsResults->sum('total_sold');

        // 🔹 جلب أعلى 5 منتجات + النسبة المئوية
        $topProducts = $topProductsResults->take(5)->map(function ($product) use ($totalQuantitySold) {
            $percentage = $totalQuantitySold > 0
                ? round(($product->total_sold / $totalQuantitySold) * 100, 2)
                : 0;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'img' => $product->img,
                'total_sold' => (int) $product->total_sold,
                'percentage' => $percentage,
            ];
        });

        // 🔹 النتيجة النهائية
        return [
            'total_delivered' => $totalDelivered,
            'monthly_delivered' => $monthlyDelivered,
            'top_products' => $topProducts,
            'total_users' => $totalUsers,
            'users_dealt_with' => $usersDealtWith,
        ];
    }
}
