<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Models\FcmToken;
use App\Models\Store\Product;
use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use Illuminate\Support\Facades\DB;
use App\Models\Financial\OrderItem;
use App\Events\Order\NewOrderCreated;
use App\Models\Financial\OrderExpense;
use App\Models\General\NotificationsCenter;
use App\Services\Notifaction\FirebaseService;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Notifaction\NotificationsCenterService;

class OrderService
{
    /**
     * Security (Stage 1): interim ownership boundary for order fulfilment.
     *
     * updateStatus() moves stock between users and accrues debt into
     * order_expenses, but took a caller-supplied order id with no authorization
     * at all. Only a supplier that actually owns at least one product in the
     * order may drive its status.
     *
     * NOTE: this contains the access-control hole only. The non-idempotent
     * accrual, the missing transaction and the caller-derived supplier_id
     * (audit FIN-14/FIN-15) are order-flow defects deferred to Stage 7.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function guardOrderSupplier($user, Order $order): void
    {
        $ownsAnyLine = $user && $order->orderItems()
            ->whereHas('product', fn($q) => $q->where('user_id', $user->id))
            ->exists();

        if (!$ownsAnyLine) {
            throw new AuthorizationException('عفوا، ليس لديك صلاحية على هذا الطلب.');
        }
    }

    /**
     * Security (Stage 1): the doctor who placed the order is the only party that
     * may request its deletion or request a line-item return.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function guardOrderDoctor($user, Order $order): void
    {
        if (!$user || (int) $order->doctor_id !== (int) $user->id) {
            throw new AuthorizationException('عفوا، ليس لديك صلاحية على هذا الطلب.');
        }
    }

    /**
     * Security (Stage 1): approving/rejecting a line-item return moves stock and
     * rewrites the ledger. Only the supplier that owns the product may do it.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function guardOrderItemSupplier($user, OrderItem $orderItem): void
    {
        if (!$user || (int) optional($orderItem->product)->user_id !== (int) $user->id) {
            throw new AuthorizationException('عفوا، ليس لديك صلاحية على هذا المنتج.');
        }
    }

    // عرض قائمة الطلبات للمورد والطبيب
    public function indexForTypes($user, $perPage = 10)
    {
        $query = Order::query();

        // fillter by doctor
        if ($user->department?->code == 'doctor') {
            $query->where('status', '!=', 'delivered')->where('doctor_id', $user->id);
        } else {
            $query->whereNotIn('status', ['delivered', 'rejected'])
                ->whereHas('orderItems.product', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function getRefundOrder($user, $perPage = 10)
    {
        $query = Order::query()
            ->with(['doctor', 'orderItems' => function ($q) {
                // نجلب فقط المنتجات المطلوب إرجاعها أو كل المنتجات في حالة كان الطلب نفسه مطلوب للإرجاع
                $q->where(function ($subQ) {
                    $subQ->where('status', 'delete_pending')
                        ->orWhereHas('order', function ($orderQ) {
                            $orderQ->where('status', 'delete_pending');
                        });
                })->with('product');
            }])
            ->where(function ($q) {
                // الطلب نفسه مطلوب إرجاعه أو يحتوي على منتج مطلوب إرجاعه
                $q->where('status', 'delete_pending')
                    ->orWhereHas('orderItems', function ($subQ) {
                        $subQ->where('status', 'delete_pending');
                    });
            })
            ->orderBy('created_at', 'desc');

        // فلترة حسب المستخدم (طبيب أو مورد)
        if ($user->department?->code == 'doctor') {
            $query->where('doctor_id', $user->id);
        } else {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        }

        return $query->paginate($perPage);
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
        if ($doctorName = request()->get('search')) {
            $query->whereHas('doctor', function ($q) use ($doctorName) {
                $q->where('name', 'like', '%' . $doctorName . '%');
            });
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
            'notes' => $data['notes'] ?? null,
            //'status' => $data['status'],
            'payment_method' => $data['payment_method'] ?? 'مدفوعات',
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

        $firebase = new FirebaseService();
        // 🔥 إنشاء إشعار لكل مورد
        foreach ($supplierIds as $supplierId) {
            $order->notificationsCenters()->create([
                'user_id'  => $supplierId, // 👈 المورد
                'title'    => 'طلب جديد',
                'message'  => "📦 تم إنشاء طلب جديد!<br>"
                    . "🔹 رقم الطلب: #{$order->id}<br>"
                    . "👨‍⚕️ الطبيب: {$user->name}",
                'type'     => 'inbox',
                'color'    => 'blue',
            ]);

            // $tokens = FcmToken::where('user_id', $supplierId)->pluck('fcm_token');
            // foreach ($tokens as $token) {
            //     $firebase->send(
            //         'طلب جديد 📦',
            //         'تم إنشاء طلب جديد برقم #' . $order->id . ' بواسطة الطبيب ' . $user->name,
            //         $token,
            //         '/operations/current-orders'
            //     );
            // }
        }

        return $order;
    }

    // تحديث حالة الطلب
    public function updateStatus($user, int $orderId, array $data)
    {
        $order = Order::findOrFail($orderId);

        $this->guardOrderSupplier($user, $order);

        $oldStatus = $order->status; // 👈 حفظ الحالة القديمة قبل التغيير

        // تحديث الحالة الجديدة
        $order->status = $data['status'];
        $order->save();

        // 🟢 في حالة التوصيل (delivered)
        if ($data['status'] == 'delivered') {
            $total = 0;

            foreach ($order->orderItems as $item) {
                $product = $item->product;
                $quantity = $item->quantity;

                // 🔹 تقليل الكمية من المورد
                if ($product && $product->user_id == $user->id) {
                    $product->decrement('quantity', $quantity);
                }

                // 🔹 زيادة الكمية عند الطبيب
                $doctorProduct = Product::where('user_id', $order->doctor_id)
                    ->where('name', $product->name)
                    ->first();

                if ($doctorProduct) {
                    $doctorProduct->increment('quantity', $quantity);
                } else {

                    // لو الطبيب ماعندهش المنتج أصلاً، نعمل نسخة بسيطة منه
                    Product::create([
                        'user_id'   => $order->doctor_id,
                        'name'      => $product->name,
                        'price'     => $product->price,
                        'quantity'  => $quantity,
                        'unit'      => $product->unit,
                        'category_id' => $product->category_id,
                        'img'  => $product->getRawOriginal('img'),
                        'desc' => $product->desc,
                    ]);
                }
            }

            if (is_null($order->price)) {
                // طلب عادي: مجموع المنتجات
                $total = $order->orderItems->sum(function ($item) {
                    return optional($item->product)->price * $item->quantity;
                });
            } else {
                // باقة: السعر الثابت
                $total = $order->price;
            }

            // 2️⃣ جلب السجل الحالي أو إنشاؤه إن لم يكن موجودًا
            $orderExpense = OrderExpense::firstOrNew([
                'doctor_id'   => $order->doctor_id,
                'supplier_id' => $user->id,
            ]);

            // 3️⃣ زيادة القيم وليس استبدالها
            $orderExpense->total     = ($orderExpense->total ?? 0) + $total;
            $orderExpense->remaining = ($orderExpense->remaining ?? 0) + $total;

            // لا نلمس paid هنا — يحدث عند الدفع فقط
            $orderExpense->save();
        }

        // 🟡 في حالة تأكيد الحذف (الموافقة على الإرجاع)
        if ($data['status'] === 'confirmed' && $oldStatus === 'delete_pending') {

            foreach ($order->orderItems as $item) {
                $product = $item->product;
                $returnedQty = $item->quantity; // ✅ إرجاع كامل الكمية

                // 🔹 تقليل الكمية من الطبيب
                $doctorProduct = Product::where('user_id', $order->doctor_id)
                    ->where('name', $product->name)
                    ->first();

                if ($doctorProduct) {
                    $doctorProduct->decrement('quantity', $returnedQty);
                }

                // 🔹 زيادة الكمية عند المورد
                if ($product) {
                    $product->increment('quantity', $returnedQty);
                }
            }

            $this->delete($order);
            return 'تم حذف الطلب بنجاح وتم تحديث المخزون';
        }

        // 🔴 في حالة رفض الإرجاع (رفض الحذف)
        if ($data['status'] === 'rejected' && $oldStatus === 'delete_pending') {
            $order->update(['status' => 'delivered']);
            return 'تم رفض طلب الحذف وإعادة الطلب إلى حالته السابقة';
        }

        // 🔔 إرسال إشعار للطبيب
        $order->notificationsCenters()->create([
            'user_id'  => $order->doctor_id,
            'title'    => 'تحديث حالة الطلب',
            'message'  => "قام المورد {$user->name} بتحديث حالة الطلب رقم #{$order->id}<br>"
                . "🔹 الحالة الجديدة: \"{$order->status_name}\"",
            'type'     => 'inbox',
            'color'    => 'blue',
        ]);

        // 🔥 إشعارات FCM (اختياري)
        // $tokens = FcmToken::where('user_id', $order->doctor_id)->pluck('fcm_token');
        // $firebase = new FirebaseService();
        // foreach ($tokens as $token) {
        //     $firebase->send(
        //         'تحديث حالة الطلب',
        //         'قام المورد ' . $user->name . ' بتحديث حالة الطلب #' . $order->id . ' إلى "' . $order->status_name . '"',
        //         $token,
        //         '/operations/current-orders'
        //     );
        // }

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

            $firebase = new FirebaseService();
            foreach ($supplierIds as $supplierId) {
                $order->notificationsCenters()->create([
                    'user_id' => $supplierId,
                    'title'   => 'تحديث على الطلب',
                    'message' => "🔄 قام الطبيب {$order->doctor->name} بتحديث الطلب.<br>"
                        . "🧾 رقم الطلب: #{$order->id}",
                    'type'     => 'inbox',
                    'color'    => 'blue',
                ]);

                // $tokens = FcmToken::where('user_id', $supplierId)->pluck('fcm_token');
                // foreach ($tokens as $token) {
                //     $firebase->send(
                //         'تحديث على الطلب',
                //         'قام الطبيب ' . $order->doctor->name . ' بتحديث الطلب رقم #' . $order->id,
                //         $token,
                //         '/operations/current-orders'
                //     );
                // }
            }

            return $order;
        });
    }

    // public function updateItemStatus($data, OrderItem $orderItem)
    // {
    //     $currentStatus = $orderItem->status;

    //     // ✅ المورد وافق على الحذف
    //     if ($data['status'] === 'confirmed' && $currentStatus === 'delete_pending') {
    //         $order = $orderItem->order;
    //         $doctorId = $order->doctor_id;
    //         $supplierId = $orderItem->product->user_id;

    //         $unitPrice = $orderItem->product->price;
    //         $refundValue = $unitPrice * $orderItem->quantity;

    //         // تحديث حساب المورد في جدول OrderExpense
    //         $expense = OrderExpense::where('doctor_id', $doctorId)
    //             ->where('supplier_id', $supplierId)
    //             ->first();

    //         if ($expense) {
    //             $expense->total     = max(0, $expense->total - $refundValue);
    //             $expense->remaining = max(0, $expense->remaining - $refundValue);
    //             $expense->save();
    //         }

    //         // حذف المنتج فعلياً
    //         $orderItem->delete();

    //         return [
    //             'message' => 'تم حذف المنتج بنجاح بعد موافقتك.',
    //             'status' => 'confirmed'
    //         ];
    //     }

    //     // ❌ المورد رفض الحذف
    //     if ($data['status'] === 'rejected' && $currentStatus === 'delete_pending') {
    //         $orderItem->update([
    //             'status' => 'confirmed',
    //         ]);

    //         return [
    //             'message' => 'تم رفض طلب حذف المنتج وتمت إعادته إلى حالته السابقة.',
    //             'status' => 'rejected'
    //         ];
    //     }

    //     return [
    //         'message' => 'لا توجد عملية مناسبة لهذه الحالة.',
    //         'status' => 'ignored'
    //     ];
    // }

    public function updateItemStatus(array $data, OrderItem $orderItem, $user = null)
    {
        $this->guardOrderItemSupplier($user, $orderItem);

        $currentStatus = $orderItem->status;

        // ✅ المورد وافق على إرجاع المنتج
        if ($data['status'] === 'confirmed' && $currentStatus === 'delete_pending') {
            DB::transaction(function () use ($orderItem, $data) {
                $order = $orderItem->order;
                $doctorId = $order->doctor_id;
                $supplierId = $orderItem->product->user_id;

                // الكمية المطلوب حذفها (مرسلة من الطبيب وقت الطلب)
                $quantityToReturn = $orderItem->returned_quantity ?? 0;

                if ($quantityToReturn <= 0 || $quantityToReturn > $orderItem->quantity) {
                    throw new \InvalidArgumentException("الكمية المطلوبة غير صالحة.");
                }

                $unitPrice = $orderItem->product->price;
                $refundValue = $unitPrice * $quantityToReturn;

                /** ✅ تحديث كميات المنتج **/
                $product = $orderItem->product;

                // 🔹 تقليل الكمية من الطبيب
                $doctorProduct = Product::where('user_id', $doctorId)
                    ->where('name', $product->name)
                    ->first();

                if ($doctorProduct) {
                    $doctorProduct->decrement('quantity', $quantityToReturn);
                    $orderItem->decrement('quantity', $quantityToReturn);
                }

                // 🔹 زيادة الكمية عند المورد
                $product->increment('quantity', $quantityToReturn);

                // ✅ تحديث حساب المورد في OrderExpense
                $expense = OrderExpense::where('doctor_id', $doctorId)
                    ->where('supplier_id', $supplierId)
                    ->first();

                if ($expense) {
                    $expense->total     = max(0, $expense->total - $refundValue);
                    $expense->remaining = max(0, $expense->remaining - $refundValue);
                    $expense->save();
                }

                // ✅ تحديث الحالة
                $orderItem->status = 'confirmed';
                $orderItem->returned_quantity = null;
                $orderItem->save();

                // إرسال إشعار للطبيب بالموافقة
                $orderItem->notificationsCenters()->create([
                    'user_id' => $doctorId,
                    'title'   => 'تمت الموافقة على إرجاع المنتج',
                    'message' => "✅ وافق المورد على إرجاع المنتج {$orderItem->product->name} من الطلب رقم #{$orderItem->order_id}.",
                    'type'    => 'cart',
                    'color'   => 'green',
                ]);
            });

            return [
                'message' => 'تمت الموافقة على الإرجاع وتحديث الكمية والحسابات بنجاح.',
                'status'  => 'confirmed'
            ];
        }

        // ❌ المورد رفض الإرجاع
        if ($data['status'] === 'rejected' && $currentStatus === 'delete_pending') {
            $orderItem->update([
                'status' => 'confirmed',
                'returned_quantity' => null,
            ]);

            // إشعار للطبيب بالرفض
            $orderItem->notificationsCenters()->create([
                'user_id' => $orderItem->order->doctor_id,
                'title'   => 'تم رفض طلب الإرجاع',
                'message' => "❌ تم رفض طلب إرجاع المنتج {$orderItem->product->name} من الطلب رقم #{$orderItem->order_id}.",
                'type'    => 'cart',
                'color'   => 'red',
            ]);

            return [
                'message' => 'تم رفض طلب إرجاع المنتج.',
                'status'  => 'rejected'
            ];
        }

        return [
            'message' => 'لا توجد عملية مناسبة لهذه الحالة.',
            'status'  => 'ignored'
        ];
    }


    // حذف منتج من الطلب
    public function deleteItem(OrderItem $orderItem)
    {
        return DB::transaction(function () use ($orderItem) {
            $order = $orderItem->order;
            $doctorId = $order->doctor_id;
            $supplierId = $orderItem->product->user_id;

            $unitPrice = $orderItem->product->price;
            $refundValue = $unitPrice * $orderItem->quantity;

            // تحديث حساب المورد
            $expense = OrderExpense::where('doctor_id', $doctorId)
                ->where('supplier_id', $supplierId)
                ->first();

            if ($expense) {
                $expense->total     = max(0, $expense->total - $refundValue);
                $expense->remaining = max(0, $expense->remaining - $refundValue);
                $expense->save();
            }

            $orderItem->delete();

            return true;
        });
    }

    // تعديل منتج من الطلب
    public function UpdateItem(OrderItem $orderItem, $data, $user = null)
    {
        // Security (Stage 1): changing a line quantity changes what the order is
        // worth, so it is restricted to the doctor who placed the order.
        $order = $orderItem->order;
        if (!$order) {
            throw new AuthorizationException('عفوا، ليس لديك صلاحية على هذا المنتج.');
        }
        $this->guardOrderDoctor($user, $order);

        return DB::transaction(function () use ($orderItem, $data) {
            $orderItem->update($data);
            return $orderItem;
        });
    }

    // حذف الطلب
    public function delete(Order $order)
    {
        return DB::transaction(function () use ($order) {
            // if ($order->status === 'delivered') {
            //     throw new \InvalidArgumentException('عفوا, لم يعد بالامكان حذف الطلب');
            // }
            $order->load('orderItems.product');

            $doctorId = $order->doctor_id;

            // لكل عنصر في الطلب
            foreach ($order->orderItems as $item) {
                $product     = $item->product;
                $supplierId  = $product->user_id;
                $unitPrice   = $product->price;
                $refundValue = $unitPrice * $item->quantity;

                // 1️⃣ إعادة الكمية إلى المخزون
                //$product->increment('quantity', $item->quantity);

                // 2️⃣ تحديث حساب المورد (OrderExpense)
                $expense = OrderExpense::where('doctor_id', $doctorId)
                    ->where('supplier_id', $supplierId)
                    ->first();

                if ($expense) {
                    $expense->total     = max(0, $expense->total - $refundValue);
                    $expense->remaining = max(0, $expense->remaining - $refundValue);
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

    public function requestDelete($user, Order $order)
    {
        // Security (Stage 1): only the ordering doctor may flag their own order.
        $this->guardOrderDoctor($user, $order);

        $order->update([
            'status' => 'delete_pending',
        ]);

        // الحصول على المورد (من أول منتج في الطلب)
        // $supplierId = optional($order->orderItems->first()->product)->user_id;

        // // إشعار المورد
        // $order->notificationsCenters()->create([
        //     'user_id' => $supplierId,
        //     'title'   => 'طلب حذف طلب',
        //     'message' => "⚠️ قام الطبيب {$user->name} بطلب حذف الطلب رقم #{$order->id}.<br>⏳ الحالة: بانتظار تأكيدك.",
        //     'type'    => 'order',
        //     'color'   => 'red',
        // ]);

        // إرسال إشعار FCM إن رغبت
        // $tokens = FcmToken::where('user_id', $supplierId)->pluck('fcm_token');
        // $firebase = new FirebaseService();
        // foreach ($tokens as $token) {
        //     $firebase->send(
        //         'طلب حذف طلب',
        //         'قام الطبيب ' . $user->name . ' بطلب حذف الطلب رقم #' . $order->id . ' وهو بانتظار تأكيدك.',
        //         $token,
        //         '/orders/current-orders'
        //     );
        // }

        return $order;
    }

    public function requestDeleteItem($quantity, $user, OrderItem $orderItem)
    {
        // Security (Stage 1): only the doctor who placed the parent order may
        // request a return on one of its lines.
        $order = $orderItem->order;
        if (!$order) {
            throw new AuthorizationException('عفوا، ليس لديك صلاحية على هذا المنتج.');
        }
        $this->guardOrderDoctor($user, $order);

        $orderItem->update([
            'status' => 'delete_pending',
            'returned_quantity' => ($orderItem->returned_quantity ?? 0) + $quantity,
        ]);

        $supplierId = $orderItem->product->user_id;

        $orderItem->notificationsCenters()->create([
            'user_id' => $supplierId,
            'title'   => 'طلب حذف منتج من الطلب',
            'message' => "⚠️ قام الطبيب {$user->name} بطلب حذف المنتج {$orderItem->product->name} من الطلب رقم #{$orderItem->order_id}.<br>⏳ الحالة: بانتظار تأكيدك.",
            'type'    => 'cart',
            'color'   => 'orange',
        ]);

        // إشعار FCM (اختياري)
        $tokens = FcmToken::where('user_id', $supplierId)->pluck('fcm_token');
        $firebase = new FirebaseService();
        foreach ($tokens as $token) {
            $firebase->send(
                'طلب حذف منتج من الطلب',
                'قام الطبيب ' . $user->name . ' بطلب حذف المنتج ' . $orderItem->product->name . ' من الطلب رقم #' . $orderItem->order_id,
                $token,
                '/orders/current-orders'
            );
        }

        return $orderItem;
    }
}
