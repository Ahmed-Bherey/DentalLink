<?php

namespace App\Services\Financial;

use App\Models\Financial\Order;
use Illuminate\Support\Facades\DB;
use App\Models\Financial\OrderExpense;

class OrderService
{
    // عرض قائمة الطلبات للمورد والطبيب
    public function indexForTypes($user)
    {
        $query = Order::query();

        // fillter by doctor
        if (request()->has('doctor_id') && request('doctor_id') !== null) {
            $query->where('doctor_id', request('doctor_id'));
        } else {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        return $query->get();
    }

    // عرض قائمة الطلبات المسلمة للمورد والطبيب
    public function getDeliveredOrders($user)
    {
        $query = Order::query();

        // fillter by doctor
        if (request()->has('doctor_id') && request('doctor_id') !== null) {
            $query->where('doctor_id', request('doctor_id'));
        } else {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->where('status', 'delivered')
                ->where('payment_method', 'like', '%مدفوعات%');
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    // create order
    public function store($user, $data)
    {
        $order = Order::create([
            'doctor_id' => $user->id,
            'notes' => $data['notes'],
            'status' => $data['status'],
            'payment_method' => $data['payment_method'],
        ]);

        foreach ($data['products'] as $product) {
            $order->orderItems()->create([
                'product_id' => $product['id'],
                'quantity' => $product['quantity'],
            ]);
        }

        return $order;
    }

    // تحديث حالة الطلب
    public function updateStatus($user, int $orderId, array $data)
    {
        $order = Order::findOrFail($orderId);
        $order->status = $data['status'];
        $order->save();

        if ($data['status'] == 'delivered') {
            $total = 0;

            foreach ($order->orderItems as $item) {
                $product = $item->product;
                if ($product) {
                    $total += $product->price * $item->quantity;
                }
            }

            $remaining = $total;

            OrderExpense::updateOrCreate(
                [
                    'doctor_id' => $order->doctor_id,
                    'supplier_id' => $user->id,
                ],
                [
                    'total'     => $total,
                    'remaining' => $remaining,
                ]
            );
        }

        return $order;
    }

    // تحديث بيانات الطلب
    public function update(Order $order, array $data)
    {
        return DB::transaction(function () use ($order, $data) {
            // تحديث الحقول الأساسية
            $order->update([
                'notes'          => $data['notes'] ?? $order->notes,
                'status'         => $data['status'] ?? $order->status,
                'payment_method' => $data['payment_method'] ?? $order->payment_method,
            ]);

            // تحديث المنتجات فقط إذا تم إرسالها
            if (array_key_exists('products', $data)) {
                // حذف القديمة
                $order->orderItems()->delete();

                // إنشاء الجديدة
                foreach ($data['products'] as $product) {
                    $order->orderItems()->create([
                        'product_id' => $product['id'],
                        'quantity'   => $product['quantity'],
                    ]);
                }
            }

            return $order;
        });
    }

    // حذف الطلب
    public function delete(Order $order)
    {
        return DB::transaction(function () use ($order) {
            $order->delete();
            return true;
        });
    }
}
