<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Models\Financial\Order;
use Illuminate\Support\Facades\DB;
use App\Models\Financial\OrderItem;
use App\Models\Financial\OrderExpense;
use Illuminate\Auth\Access\AuthorizationException;

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
            $order->load('orderItems.product');

            $totalToRefund = $order->total_order_price;

            // الحصول على المورد من أول منتج
            $firstItem = $order->orderItems->first();
            $supplierId = $firstItem?->product?->user_id;

            if ($supplierId) {
                $expense = OrderExpense::where('doctor_id', $order->doctor_id)
                    ->where('supplier_id', $supplierId)
                    ->first();

                if ($expense) {
                    $expense->total     = max(0, $expense->total - $totalToRefund);
                    $expense->remaining = max(0, $expense->remaining - $totalToRefund);
                    $expense->save();
                }
            }

            // حذف عناصر الطلب ثم الطلب نفسه
            $order->orderItems()->delete();
            $order->delete();

            return true;
        });
    }

    public function returnOrderItem(User $doctor, int $orderItemId, int $quantityToReturn): void
    {
        DB::transaction(function () use ($doctor, $orderItemId, $quantityToReturn) {
            $orderItem = OrderItem::with(['order', 'product'])->findOrFail($orderItemId);

            // تحقق أن الطبيب هو صاحب الطلب
            // if ($orderItem->order->doctor_id !== $doctor->id) {
            //     throw new AuthorizationException();
            // }

            // تحقق أن الكمية المسترجعة منطقية
            if ($quantityToReturn <= 0 || $quantityToReturn > $orderItem->quantity) {
                throw new \InvalidArgumentException("الكمية المطلوبة غير صالحة.");
            }

            // احسب قيمة المسترجع
            $unitPrice = $orderItem->product->price;
            $totalRefund = $unitPrice * $quantityToReturn;

            // حدث سجل الطلب
            $orderItem->quantity -= $quantityToReturn;
            if ($orderItem->quantity === 0) {
                $orderItem->delete();
            } else {
                $orderItem->save();
            }

            // أعد الكمية للمخزون
            $orderItem->product->increment('quantity', $quantityToReturn);

            // حدث سجل المصاريف (OrderExpense)
            $expense = OrderExpense::where('doctor_id', $doctor->id)
                ->where('supplier_id', $orderItem->product->user_id)
                ->first();

            if ($expense) {
                $expense->total     = max(0, $expense->total - $totalRefund);
                $expense->remaining = max(0, $expense->remaining - $totalRefund);
                $expense->save();
            }
        });
    }
}
