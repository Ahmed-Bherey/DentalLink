<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Models\Store\Product;
use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Services\FirebaseService;
use Illuminate\Support\Facades\DB;
use App\Models\Financial\OrderItem;
use App\Events\Order\NewOrderCreated;
use App\Models\Financial\OrderExpense;
use App\Models\General\NotificationsCenter;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Notifaction\NotificationsCenterService;

class OrderService
{
    // عرض قائمة الطلبات للمورد والطبيب
    public function indexForTypes($user, $perPage = 10)
    {
        $query = Order::query();

        // fillter by doctor
        if ($user->department?->code == 'doctor') {
            $query->where('status', '!=', 'delivered')->where('doctor_id', $user->id);
        } else {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->whereNotIn('status', ['delivered', 'rejected'])
                    ->where('user_id', $user->id);
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    // عرض قائمة الطلبات المسلمة للمورد والطبيب
    public function getDeliveredOrders($user, $perPage = 10)
    {
        $query = Order::query()
            ->with(['doctor', 'orderItems.product'])
            ->where('status', 'delivered')
            ->orderBy('created_at', 'desc');

        // 🔹 فلترة حسب نوع المستخدم
        if ($user->department?->code === 'doctor') {
            $query->where('doctor_id', $user->id);
        } elseif ($user->department?->code === 'supplier') {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->where('payment_method', 'like', '%مدفوعات%');
        }

        // 🔹 فلترة إضافية حسب الطبيب (إذا أراد المورد مشاهدة طلبات طبيب محدد)
        if ($doctorId = request()->get('doctor_id')) {
            $query->where('doctor_id', $doctorId);
        }

        // 🔹 فلترة إضافية حسب الطبيب (إذا أراد المورد مشاهدة طلبات طبيب محدد)
        if ($supplierId = request()->get('supplier_id')) {
            $query->whereHas('orderItems.product', function ($q) use ($supplierId) {
                $q->where('user_id', $supplierId);
            });
        }

        // 🔹 فلترة بالتاريخ
        if ($from = request()->get('from_date')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = request()->get('to_date')) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query->paginate($perPage);
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

        Cart::where('doctor_id', $order->doctor_id)->delete();

        // 🔥 جلب الموردين المرتبطين بمنتجات الطلب
        $supplierIds = $order->orderItems()
            ->with('product:id,user_id')
            ->get()
            ->pluck('product.user_id')
            ->unique();

        // 🔥 إنشاء إشعار لكل مورد
        foreach ($supplierIds as $supplierId) {
            $order->notificationsCenters()->create([
                'user_id'  => $supplierId, // 👈 المورد
                'title'    => 'طلب جديد',
                'message'  => 'تم إنشاء طلب جديد برقم #' . $order->id . ' بواسطة الطبيب ' . $user->name,
                'type'     => 'order',
                'color'     => 'yellow',
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

        $order->notificationsCenters()->create([
            'user_id'  => $order->doctor_id, // 👈 إشعار للطبيب
            'title'    => 'تحديث حالة الطلب',
            'message'  => 'قام المورد ' . $user->name . ' بتحديث حالة الطلب #' . $order->id . ' إلى "' . $order->status_name . '"',
            'type'     => 'order',
            'color'    => 'blue',
        ]);

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

            $supplierIds = $order->orderItems()
                ->with('product:id,user_id')
                ->get()
                ->pluck('product.user_id')
                ->unique();

            foreach ($supplierIds as $supplierId) {
                $order->notificationsCenters()->create([
                    'user_id' => $supplierId,
                    'title'   => 'تحديث على الطلب',
                    'message' => 'قام الطبيب ' . $order->doctor->name . ' بتحديث الطلب رقم #' . $order->id,
                    'type'    => 'order',
                    'color'   => 'blue',
                ]);
            }

            return $order;
        });
    }

    // حذف منتج من الطلب
    public function deleteItem(OrderItem $orderItem)
    {
        return DB::transaction(function () use ($orderItem) {
            $orderItem->delete();

            return true;
        });
    }

    // تعديل منتج من الطلب
    public function UpdateItem(OrderItem $orderItem, $data)
    {
        return DB::transaction(function () use ($orderItem, $data) {
            $orderItem->update($data);
            return $orderItem;
        });
    }

    // حذف الطلب
    public function delete(Order $order)
    {
        return DB::transaction(function () use ($order) {
            if ($order->status === 'delivered') {
                throw new \InvalidArgumentException('عفوا, لم يعد بالامكان حذف الطلب');
            }
            $order->load('orderItems.product');

            // $totalToRefund = $order->total_order_price;

            // // الحصول على المورد من أول منتج
            // $firstItem = $order->orderItems->first();
            // $supplierId = $firstItem?->product?->user_id;

            // if ($supplierId) {
            //     $expense = OrderExpense::where('doctor_id', $order->doctor_id)
            //         ->where('supplier_id', $supplierId)
            //         ->first();

            //     if ($expense) {
            //         $expense->total     = max(0, $expense->total - $totalToRefund);
            //         $expense->remaining = max(0, $expense->remaining - $totalToRefund);
            //         $expense->save();
            //     }
            // }

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
            if ($orderItem->order->doctor_id !== $doctor->id) {
                throw new AuthorizationException();
            }

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

    // البحث باسم الطبيب او حالة الطلب
    public function searchOrders($user, $perPage = 10)
    {
        $query = Order::query();

        // جلب قيمة البحث من الريكوست
        $search = trim(request('search', ''));

        if ($search !== '') {
            // خريطة الحالات (العربي → الإنجليزي)
            $statusMap = [
                'قيد الانتظار'  => 'pending',
                'جاري التحضير' => 'preparing',
                'تم التوصيل'   => 'delivered',
                'مرفوض'         => 'rejected',
            ];

            $query->where(function ($q) use ($search, $statusMap) {
                // ✅ البحث باسم الدكتور
                $q->whereHas('doctor', function ($doctorQuery) use ($search) {
                    $doctorQuery->where('name', 'like', "%{$search}%");
                });

                // ✅ البحث بالحالة (عربي أو إنجليزي)
                if (isset($statusMap[$search])) {
                    $q->orWhere('status', $statusMap[$search]);
                } else {
                    $q->orWhere('status', 'like', "%{$search}%");
                }
            });
        }

        // ✅ فلترة الطلبات الخاصة بالمورد
        $query->whereHas('orderItems.product', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }
}
