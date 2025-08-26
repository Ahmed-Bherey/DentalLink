<?php

namespace App\Services\Financial;

use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;

class OrderService
{
    // عرض قائمة الطلبات للمورد
    public function getAllForSupplies($user)
    {
        $query = Order::whereHas('orderItems.product', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });

        // fillter by doctor
        if (request()->has('doctor_id') && request('doctor_id') !== null) {
            $query->where('doctor_id', request('doctor_id'));
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    // عرض قائمة الطلبات المسلمة للمورد
    public function getDeliveredOrdersForSupplier($user)
    {
        $query = Order::whereHas('orderItems.product', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->where('status', 'delivered')
            ->where('payment_method', 'like', '%مدفوعات%');

        // fillter by doctor
        if (request()->has('doctor_id') && request('doctor_id') !== null) {
            $query->where('doctor_id', request('doctor_id'));
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
}
