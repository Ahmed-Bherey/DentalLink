<?php

namespace App\Services\Index;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Store\Product;
use App\Models\Financial\Order;

class StatisticService
{
    public function getDashboardStats($user)
    {
        $query = Order::query()->where('status', 'delivered');

        // 🔹 فلترة الطلبات بناءً على نوع المستخدم
        if ($user->department?->code === 'doctor') {
            $query->where('doctor_id', $user->id);
        } elseif ($user->department?->code === 'supplier') {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->where('payment_method', 'like', '%مدفوعات%');
        }

        // 🔹 عدد الطلبات المسلّمة
        $totalDelivered = (clone $query)->count();

        // 🔹 عدد الطلبات المسلّمة هذا الشهر
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth   = Carbon::now()->endOfMonth();

        $monthlyDelivered = (clone $query)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();

        // 🔹 إحصائيات إضافية حسب نوع المستخدم
        if ($user->department?->code === 'doctor') {
            // عدد الموردين في النظام
            $totalSuppliers = User::whereHas('department', fn($q) => $q->where('code', 'supplier'))->count();

            // الموردين الذين تعامل معهم الطبيب
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

            // 🔹 المنتجات الأكثر شراءً
            $topProductsQuery = Product::select('products.id', 'products.name', 'products.img')
                ->selectRaw('SUM(order_items.quantity) as total_sold')
                ->join('order_items', 'products.id', '=', 'order_items.product_id')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'delivered')
                ->where('orders.doctor_id', $user->id)
                ->groupBy('products.id', 'products.name', 'products.img')
                ->orderByDesc('total_sold');
        } elseif ($user->department?->code === 'supplier') {
            // عدد الأطباء في النظام
            $totalDoctors = User::whereHas('department', fn($q) => $q->where('code', 'doctor'))->count();

            // الأطباء الذين تعامل معهم المورد
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

            // 🔹 المنتجات الأكثر مبيعًا
            $topProductsQuery = Product::select('products.id', 'products.name', 'products.img')
                ->selectRaw('SUM(order_items.quantity) as total_sold')
                ->join('order_items', 'products.id', '=', 'order_items.product_id')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'delivered')
                ->where('products.user_id', $user->id)
                ->groupBy('products.id', 'products.name', 'products.img')
                ->orderByDesc('total_sold');
        } else {
            // المدير أو المستخدم العام
            $additionalStats = [
                'total_doctors' => User::whereHas('department', fn($q) => $q->where('code', 'doctor'))->count(),
                'total_suppliers' => User::whereHas('department', fn($q) => $q->where('code', 'supplier'))->count(),
            ];

            // 🔹 المنتجات الأكثر مبيعًا في النظام
            $topProductsQuery = Product::select('products.id', 'products.name', 'products.img')
                ->selectRaw('SUM(order_items.quantity) as total_sold')
                ->join('order_items', 'products.id', '=', 'order_items.product_id')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', 'delivered')
                ->groupBy('products.id', 'products.name', 'products.img')
                ->orderByDesc('total_sold');
        }

        // 🔹 إجمالي الكمية المباعة (لإحتساب النسبة)
        $totalQuantitySold = (clone $topProductsQuery)->sum('total_sold');

        // 🔹 جلب أعلى 5 منتجات + النسبة المئوية
        $topProducts = $topProductsQuery->limit(5)->get()->map(function ($product) use ($totalQuantitySold) {
            $percentage = $totalQuantitySold > 0
                ? round(($product->total_sold / $totalQuantitySold) * 100, 2)
                : 0;
            return [
                'id' => $product->id,
                'name' => $product->name,
                'img' => $product->img,
                'total_sold' => (int)$product->total_sold,
                'percentage' => $percentage,
            ];
        });

        // 🔹 النتيجة النهائية
        return array_merge([
            'total_delivered'     => $totalDelivered,
            'monthly_delivered'   => $monthlyDelivered,
            'top_products'        => $topProducts,
        ], $additionalStats);
    }
}
