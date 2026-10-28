<?php

namespace App\Services\Financial;

use App\Exceptions\Financial\FulfilmentException;
use App\Models\FcmToken;
use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\OrderItem;
use App\Models\Store\Product;
use App\Services\Notifaction\FirebaseService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /*
     * Stage 4: the interim ownership guards that lived here
     * (guardOrderSupplier / guardOrderDoctor / guardOrderItemSupplier) have
     * been replaced by OrderPolicy and OrderItemPolicy, invoked from the
     * controller. Authorization is no longer a service concern.
     *
     * Stage 6: every mutation of order_expenses now goes through LedgerService,
     * which keeps `remaining = total - paid` and re-derives `paid` from the
     * payment rows. The previous code adjusted total and remaining separately
     * and clamped each at zero, which allowed the two to disagree.
     *
     * Stage 7: order lines now carry a frozen price snapshot (unit_price,
     * line_total, product_master_id, supplier_id) taken when the order is
     * placed. Every monetary value here reads that snapshot, never
     * products.price - so repricing a product cannot alter an existing order,
     * and an approved return refunds what was actually charged.
     */
    public function __construct(private LedgerService $ledger) {}

    // عرض قائمة الطلبات للمورد والطبيب
    public function indexForTypes($user, $perPage = 10)
    {
        $query = Order::query();

        // fillter by doctor
        if ($user->isDoctor()) {
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
        if ($user->isDoctor()) {
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
        if ($user->isDoctor()) {
            $query->where('doctor_id', $user->id);
        } elseif ($user->isSupplier()) {
            $query->whereHas('orderItems.product', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->where('payment_method', 'like', '%مدفوعات%');
        }

        // 🔹 فلترة إضافية حسب الطبيب (إذا أراد المورد مشاهدة طلبات طبيب محدد)
        if ($doctorName = request()->get('search')) {
            $query->whereHas('doctor', function ($q) use ($doctorName) {
                $q->where('name', 'like', '%'.$doctorName.'%');
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
            // 'status' => $data['status'],
            'payment_method' => $data['payment_method'] ?? 'مدفوعات',
        ]);

        // STAGE 7 — freeze the commercial terms at the moment of purchase.
        //
        // Previously only product_id and quantity were stored, so every monetary
        // value was recomputed later from products.price — a column the supplier
        // can edit at any time. Repricing a product therefore rewrote the value
        // of orders already placed. The snapshot below is what makes an order's
        // value a historical fact instead of a live query.
        foreach ($data['products'] as $product) {
            $offer = Product::findOrFail($product['id']);
            $unitPrice = Money::of($offer->price);
            $quantity = (int) $product['quantity'];

            $order->orderItems()->create([
                'product_id' => $offer->id,
                // Canonical identity and seller are recorded directly, so the
                // line stays meaningful even if the supplier later withdraws or
                // renames their offer.
                'product_master_id' => $offer->product_master_id,
                'supplier_id' => $offer->user_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => Money::mul($unitPrice, $quantity),
            ]);
        }

        Cart::where('doctor_id', $order->doctor_id)->delete();

        // 🔥 جلب الموردين المرتبطين بمنتجات الطلب
        $supplierIds = $order->orderItems()
            ->with('product:id,user_id')
            ->get()
            ->pluck('product.user_id')
            ->unique();

        $firebase = new FirebaseService;
        // 🔥 إنشاء إشعار لكل مورد
        foreach ($supplierIds as $supplierId) {
            $order->notificationsCenters()->create([
                'user_id' => $supplierId, // 👈 المورد
                'title' => 'طلب جديد',
                'message' => '📦 تم إنشاء طلب جديد!<br>'
                    ."🔹 رقم الطلب: #{$order->id}<br>"
                    ."👨‍⚕️ الطبيب: {$user->name}",
                'type' => 'inbox',
                'color' => 'blue',
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

    /**
     * Fulfil the calling supplier's own lines on an order (C-1, C-2, H-3).
     *
     * SEMANTICS
     *
     * Fulfilment is a property of a LINE, not of an order. Each supplier
     * fulfils the lines they are selling; the order as a whole is `delivered`
     * only once every line has been fulfilled, and `preparing` while some
     * remain. That is the smallest consistent model the existing schema allows
     * — `orders.status` describes the whole order, so it cannot be the record
     * of one supplier's part.
     *
     * GUARANTEES
     *
     *   - A supplier is charged for their OWN lines only (C-1).
     *   - A line can be fulfilled once. A repeat call finds nothing to do and
     *     is refused without side effects (C-2).
     *   - Stock is checked before it is moved; insufficient stock aborts the
     *     whole operation (H-3).
     *   - Everything happens in one transaction, so a failure anywhere leaves
     *     neither a charge nor a stock movement behind.
     *
     * @throws FulfilmentException
     */
    public function fulfilForSupplier($user, Order $order): string
    {
        return DB::transaction(function () use ($user, $order) {
            $supplierId = (int) $user->id;

            // Lock the lines being fulfilled. Two concurrent requests for the
            // same supplier serialise here, so the second sees fulfilled_at
            // already set and finds nothing to do.
            $lines = OrderItem::where('order_id', $order->id)
                ->where('supplier_id', $supplierId)
                ->whereNull('fulfilled_at')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw FulfilmentException::nothingToFulfil();
            }

            $charge = Money::zero();

            foreach ($lines as $line) {
                $product = Product::whereKey($line->product_id)->lockForUpdate()->first();
                $quantity = (int) $line->quantity;

                // H-3: verify BEFORE moving anything. Throwing here rolls the
                // whole transaction back, so a short line cannot leave earlier
                // lines half-fulfilled.
                $available = (int) ($product->quantity ?? 0);

                if ($product === null || $available < $quantity) {
                    throw FulfilmentException::insufficientStock(
                        $product?->name ?? '—',
                        $available,
                        $quantity
                    );
                }

                $product->decrement('quantity', $quantity);

                $this->addToDoctorInventory($order, $line, $product, $quantity);

                $line->fulfilled_at = now();
                $line->save();

                $charge = Money::add($charge, $line->historicalLineTotal());
            }

            // A package order carries one agreed price for the whole bundle, so
            // its lines have no individual value to sum. Charge the package
            // price, once, when its lines are fulfilled.
            if ($order->price !== null) {
                $charge = Money::of($order->price);
            }

            $ledger = $this->ledger->lockFor((int) $order->doctor_id, $supplierId);
            $this->ledger->addCharge($ledger, $charge);

            $this->refreshOrderFulfilmentStatus($order);

            $order->notificationsCenters()->create([
                'user_id' => $order->doctor_id,
                'title' => 'تحديث حالة الطلب',
                'message' => "قام المورد {$user->name} بتسليم أصناف من الطلب رقم #{$order->id}<br>"
                    ."🔹 الحالة الجديدة: \"{$order->fresh()->status_name}\"",
                'type' => 'inbox',
                'color' => 'blue',
            ]);

            return 'تم تسليم الأصناف الخاصة بك وتحديث الحساب بنجاح';
        });
    }

    /**
     * Give the doctor the goods, against the SAME canonical product.
     */
    private function addToDoctorInventory(Order $order, OrderItem $line, Product $product, int $quantity): void
    {
        $masterId = $line->product_master_id ?? $product->product_master_id;

        $doctorProduct = Product::where('user_id', $order->doctor_id)
            ->where('product_master_id', $masterId)
            ->lockForUpdate()
            ->first();

        if ($doctorProduct !== null) {
            $doctorProduct->increment('quantity', $quantity);

            return;
        }

        // `price` carries what was actually paid, for display in the doctor's
        // own stock list. A doctor's row is inventory, not an offer.
        Product::create([
            'user_id' => $order->doctor_id,
            'product_master_id' => $masterId,
            'name' => $product->name,
            'price' => $line->unit_price ?? $product->price,
            'quantity' => $quantity,
            'category_id' => $product->category_id,
            'img' => $product->getRawOriginal('img'),
            'desc' => $product->desc,
        ]);
    }

    /**
     * `delivered` once every line is fulfilled, `preparing` while some are not.
     */
    private function refreshOrderFulfilmentStatus(Order $order): void
    {
        $outstanding = OrderItem::where('order_id', $order->id)->whereNull('fulfilled_at')->count();

        $order->status = $outstanding === 0 ? 'delivered' : 'preparing';
        $order->save();
    }

    /**
     * Approve the return of an entire order (H-2).
     *
     * Replaces a path that deleted the order outright. Now every still-held
     * line is refunded at ITS OWN frozen price through LedgerService, exactly
     * as a per-line return is, and the records survive.
     *
     * Quantities already returned line-by-line are excluded, so the same unit
     * can never be refunded twice. Lines that were never fulfilled are skipped
     * — there is no obligation to reverse.
     *
     * @throws FulfilmentException
     */
    public function returnWholeOrder($user, Order $order): string
    {
        return DB::transaction(function () use ($user, $order) {
            $lines = OrderItem::where('order_id', $order->id)->lockForUpdate()->get();

            $refundedAnything = false;

            foreach ($lines as $line) {
                $quantity = $line->returnableQuantity();

                if ($quantity <= 0) {
                    continue;   // never fulfilled, or already fully returned
                }

                if ($line->unit_price === null) {
                    // A package component has no individual price, so its
                    // refund cannot be priced. See returnWholeOrder()'s note in
                    // PackageService — pending a business decision on how a
                    // package price is allocated across its components.
                    throw FulfilmentException::noPriceSnapshot();
                }

                $supplierId = (int) ($line->supplier_id ?? $line->product?->user_id);
                $product = Product::whereKey($line->product_id)->lockForUpdate()->first();

                if ($product !== null) {
                    $product->increment('quantity', $quantity);
                }

                $this->removeFromDoctorInventory($order, $line, $quantity);

                $ledger = $this->ledger->lockFor((int) $order->doctor_id, $supplierId);

                $this->ledger->recordRefund(
                    $ledger,
                    $line,
                    $quantity,
                    $line->historicalUnitPrice(),
                    $user?->id
                );

                $line->returned_quantity = (int) $line->returned_quantity + $quantity;
                $line->pending_return_quantity = 0;
                $line->status = 'confirmed';
                $line->save();

                $refundedAnything = true;
            }

            if (! $refundedAnything) {
                throw FulfilmentException::nothingReturnable(0);
            }

            // The order is kept, marked returned. Deleting it would destroy the
            // very history the refund records are there to explain.
            $order->status = 'returned';
            $order->save();

            return 'تمت الموافقة على إرجاع الطلب وتحديث المخزون والحسابات';
        });
    }

    private function removeFromDoctorInventory(Order $order, OrderItem $line, int $quantity): void
    {
        $masterId = $line->product_master_id ?? $line->product?->product_master_id;

        if ($masterId === null) {
            return;
        }

        $doctorProduct = Product::where('user_id', $order->doctor_id)
            ->where('product_master_id', $masterId)
            ->lockForUpdate()
            ->first();

        if ($doctorProduct !== null) {
            // Floored at zero: the doctor may have consumed the stock already,
            // and a negative inventory row is never correct.
            $doctorProduct->quantity = max(0, (int) $doctorProduct->quantity - $quantity);
            $doctorProduct->save();
        }
    }

    // تحديث حالة الطلب
    public function updateStatus($user, int $orderId, array $data)
    {
        $order = Order::findOrFail($orderId);

        $oldStatus = $order->status; // 👈 حفظ الحالة القديمة قبل التغيير

        // 🟢 FULFILMENT (delivered) — handled separately and transactionally.
        //
        // Everything about this branch used to be wrong in three ways at once:
        //
        //   C-1  `$total` summed EVERY line and charged it to whoever called
        //        this, so delivering a mixed-supplier order billed one supplier
        //        for another's goods and left the other with no ledger at all.
        //   C-2  Nothing recorded that a line had been fulfilled, so calling
        //        this twice charged twice and moved stock twice.
        //   H-3  Stock was decremented with no check, so it went negative.
        //
        // fulfilForSupplier() replaces it: it acts only on the caller's own
        // unfulfilled lines, inside one transaction, with the ledger locked.
        if ($data['status'] === 'delivered') {
            return $this->fulfilForSupplier($user, $order);
        }

        // تحديث الحالة الجديدة
        $order->status = $data['status'];
        $order->save();

        // 🟡 في حالة تأكيد الحذف (الموافقة على الإرجاع)
        //
        // H-2: this used to move stock with a name-matched lookup and then call
        // delete(), which reversed the charge at the CURRENT product price with
        // float arithmetic, recorded no refund (so `paid` never moved), and
        // hard-deleted the order and its lines — destroying the financial
        // history Stages 5-7 exist to protect.
        if ($data['status'] === 'confirmed' && $oldStatus === 'delete_pending') {
            return $this->returnWholeOrder($user, $order);
        }

        // 🔴 في حالة رفض الإرجاع (رفض الحذف)
        if ($data['status'] === 'rejected' && $oldStatus === 'delete_pending') {
            $order->update(['status' => 'delivered']);

            return 'تم رفض طلب الحذف وإعادة الطلب إلى حالته السابقة';
        }

        // 🔔 إرسال إشعار للطبيب
        $order->notificationsCenters()->create([
            'user_id' => $order->doctor_id,
            'title' => 'تحديث حالة الطلب',
            'message' => "قام المورد {$user->name} بتحديث حالة الطلب رقم #{$order->id}<br>"
                ."🔹 الحالة الجديدة: \"{$order->status_name}\"",
            'type' => 'inbox',
            'color' => 'blue',
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
                'notes' => $data['notes'] ?? $order->notes,
                'status' => $data['status'] ?? $order->status,
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
                        'quantity' => $product['quantity'],
                    ]);
                }
            }

            $supplierIds = $order->orderItems()
                ->with('product:id,user_id')
                ->get()
                ->pluck('product.user_id')
                ->unique();

            $firebase = new FirebaseService;
            foreach ($supplierIds as $supplierId) {
                $order->notificationsCenters()->create([
                    'user_id' => $supplierId,
                    'title' => 'تحديث على الطلب',
                    'message' => "🔄 قام الطبيب {$order->doctor->name} بتحديث الطلب.<br>"
                        ."🧾 رقم الطلب: #{$order->id}",
                    'type' => 'inbox',
                    'color' => 'blue',
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
        $currentStatus = $orderItem->status;

        // ✅ المورد وافق على إرجاع المنتج
        if ($data['status'] === 'confirmed' && $currentStatus === 'delete_pending') {
            DB::transaction(function () use ($orderItem, $user) {
                $order = $orderItem->order;
                $doctorId = (int) $order->doctor_id;

                // STAGE 7: the seller is read from the line's own snapshot, not
                // by walking to the product's CURRENT owner — which changes if
                // the product is ever reassigned, and vanishes with the product.
                $supplierId = (int) ($orderItem->supplier_id ?? $orderItem->product->user_id);

                // الكمية المطلوب حذفها (مرسلة من الطبيب وقت الطلب)
                $quantityToReturn = (int) ($orderItem->pending_return_quantity ?? 0);

                // C-3: re-checked at APPROVAL, not only at request time. The
                // line could have been fulfilled, returned or amended in the
                // interval, and the approval is the step that moves money.
                if (! $orderItem->isFulfilled()) {
                    throw FulfilmentException::notFulfilled();
                }

                // H-1: a package component has no individual price, so its
                // refund cannot be priced. Refuse rather than refund the
                // product's CURRENT price, which is what the fallback in
                // historicalUnitPrice() would otherwise return.
                if ($orderItem->unit_price === null) {
                    throw FulfilmentException::noPriceSnapshot();
                }

                // Delivered, less what has already been approved. Measured
                // against fulfilment rather than purchase, so a partially
                // fulfilled order cannot over-refund.
                $returnable = $orderItem->returnableQuantity();

                if ($quantityToReturn <= 0 || $quantityToReturn > $returnable) {
                    throw FulfilmentException::nothingReturnable($returnable);
                }

                /** ✅ تحديث كميات المنتج **/
                $product = $orderItem->product;

                // 🔹 تقليل الكمية من الطبيب — matched by canonical identity.
                $doctorProduct = Product::where('user_id', $doctorId)
                    ->where('product_master_id', $orderItem->product_master_id ?? $product->product_master_id)
                    ->first();

                if ($doctorProduct) {
                    $doctorProduct->decrement('quantity', $quantityToReturn);
                }

                // 🔹 زيادة الكمية عند المورد
                if ($product) {
                    $product->increment('quantity', $quantityToReturn);
                }

                // STAGE 7 — THE REFUND.
                //
                // Priced from the line's FROZEN unit price. This is the defect
                // Stage 6 identified and could not fix: the refund used
                // $orderItem->product->price, so a supplier who raised a price
                // after the sale was refunding more than they were ever paid,
                // and one who lowered it refunded less.
                //
                // recordRefund() writes the refund row AND reduces the charge in
                // one operation, so the doctor's effective paid amount and their
                // obligation always move together. Both go through LedgerService;
                // nothing here touches paid or remaining directly.
                $ledger = $this->ledger->lockFor($doctorId, $supplierId);

                $this->ledger->recordRefund(
                    $ledger,
                    $orderItem,
                    $quantityToReturn,
                    $orderItem->historicalUnitPrice(),
                    $user?->id
                );

                // ✅ تحديث الحالة
                //
                // `quantity` is NOT decremented: it is the immutable purchased
                // quantity. The cumulative returned total is what grows, which is
                // what makes "a return may not exceed the purchase" checkable.
                $orderItem->status = 'confirmed';
                $orderItem->returned_quantity = (int) ($orderItem->returned_quantity ?? 0) + $quantityToReturn;
                $orderItem->pending_return_quantity = 0;
                $orderItem->save();

                // إرسال إشعار للطبيب بالموافقة
                $orderItem->notificationsCenters()->create([
                    'user_id' => $doctorId,
                    'title' => 'تمت الموافقة على إرجاع المنتج',
                    'message' => "✅ وافق المورد على إرجاع المنتج {$orderItem->product->name} من الطلب رقم #{$orderItem->order_id}.",
                    'type' => 'cart',
                    'color' => 'green',
                ]);
            });

            return [
                'message' => 'تمت الموافقة على الإرجاع وتحديث الكمية والحسابات بنجاح.',
                'status' => 'confirmed',
            ];
        }

        // ❌ المورد رفض الإرجاع
        if ($data['status'] === 'rejected' && $currentStatus === 'delete_pending') {
            // Rejected: discard the request. The cumulative approved total
            // (returned_quantity) is history and is never touched here.
            $orderItem->update([
                'status' => 'confirmed',
                'pending_return_quantity' => 0,
            ]);

            // إشعار للطبيب بالرفض
            $orderItem->notificationsCenters()->create([
                'user_id' => $orderItem->order->doctor_id,
                'title' => 'تم رفض طلب الإرجاع',
                'message' => "❌ تم رفض طلب إرجاع المنتج {$orderItem->product->name} من الطلب رقم #{$orderItem->order_id}.",
                'type' => 'cart',
                'color' => 'red',
            ]);

            return [
                'message' => 'تم رفض طلب إرجاع المنتج.',
                'status' => 'rejected',
            ];
        }

        return [
            'message' => 'لا توجد عملية مناسبة لهذه الحالة.',
            'status' => 'ignored',
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
                // Stage 6: see reduceCharge() - keeps remaining = total - paid.
                $this->ledger->reduceCharge($expense, $refundValue);
            }

            $orderItem->delete();

            return true;
        });
    }

    // تعديل منتج من الطلب
    /**
     * Change the quantity on an order line (H-4).
     *
     * This used to be a bare `$orderItem->update($data)`, which left
     * `line_total` at its original value while `quantity` moved. The line then
     * failed its own arithmetic (`unit_price x quantity != line_total`), and if
     * the order had already been delivered the ledger was never adjusted, so
     * the doctor was billed for a quantity they no longer had.
     *
     * AFTER FULFILMENT THE QUANTITY IS FIXED. Changing it would require a
     * controlled financial adjustment — a credit or an extra charge against a
     * balance that may already be settled or partly refunded, and it would
     * invalidate the return ceiling derived from the delivered quantity. That
     * adjustment is not a decision this change should invent, so the operation
     * is refused with a clear reason instead.
     *
     * Before fulfilment nothing has been charged, so the quantity is free to
     * change — and `line_total` is recomputed from the UNCHANGED frozen
     * `unit_price`, which keeps the historical price intact.
     *
     * @throws FulfilmentException
     */
    public function UpdateItem(OrderItem $orderItem, $data, $user = null)
    {
        return DB::transaction(function () use ($orderItem, $data) {
            $line = OrderItem::whereKey($orderItem->getKey())->lockForUpdate()->firstOrFail();

            if ($line->isFulfilled()) {
                throw FulfilmentException::quantityLockedAfterFulfilment();
            }

            if (array_key_exists('quantity', $data)) {
                $quantity = (int) $data['quantity'];

                $line->quantity = $quantity;

                // Recomputed from the frozen unit price, never from the
                // product's current one.
                if ($line->unit_price !== null) {
                    $line->line_total = Money::mul($line->unit_price, $quantity);
                }
            }

            $line->save();

            return $line;
        });
    }

    // حذف الطلب
    public function delete(Order $order)
    {
        return DB::transaction(function () use ($order) {
            $order->load('orderItems.product');

            // H-2: administrative deletion is NOT a financial return.
            //
            // A delivered line has been charged, may have been paid for, and
            // may already carry refund records that reference it (Stage 7 made
            // that foreign key RESTRICT). Deleting it would destroy financial
            // history and, where a refund exists, fail at the database with an
            // unexplained constraint error. Returns go through
            // returnWholeOrder(), which refunds and keeps the record.
            $fulfilled = $order->orderItems->filter(fn ($item) => $item->isFulfilled())->count();

            if ($fulfilled > 0) {
                throw FulfilmentException::orderHasFinancialHistory();
            }

            // No ledger reversal is needed or wanted here. The guard above
            // means no line was ever fulfilled, so nothing was ever charged —
            // the loop this replaces reduced the balance by the product's
            // CURRENT price with float arithmetic, inventing a credit for an
            // obligation that never existed.

            // حذف عناصر الطلب ثم الطلب نفسه
            $order->orderItems()->delete();
            $order->delete();

            return true;
        });
    }

    /*
     * returnOrderItem() was REMOVED here.
     *
     * It was unrouted dead code that applied a financial change with no
     * supplier approval at all, priced from the product's CURRENT price, and
     * deleted the order line when its quantity reached zero. Every one of
     * those is now a defect the surrounding code exists to prevent, and
     * leaving it in place was an invitation to route it by mistake.
     *
     * The supported path is: requestDeleteItem() -> updateItemStatus().
     */

    // البحث باسم الطبيب او حالة الطلب
    public function searchOrders($user, $perPage = 10)
    {
        $query = Order::query();

        // جلب قيمة البحث من الريكوست
        $search = trim(request('search', ''));

        if ($search !== '') {
            // خريطة الحالات (العربي → الإنجليزي)
            $statusMap = [
                'قيد الانتظار' => 'pending',
                'جاري التحضير' => 'preparing',
                'تم التوصيل' => 'delivered',
                'مرفوض' => 'rejected',
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
        $quantity = (int) $quantity;

        // C-3: goods that were never delivered cannot be returned. Previously
        // this measured against the PURCHASED quantity, so a doctor could
        // request a return on an order still sitting at `pending` — and the
        // supplier's approval then reversed an obligation that had never been
        // created, erasing real debt from an unrelated settled balance.
        if (! $orderItem->isFulfilled()) {
            throw FulfilmentException::notFulfilled();
        }

        // Delivered, less what has already been approved for return.
        $returnable = $orderItem->returnableQuantity();

        if ($quantity <= 0 || $quantity > $returnable) {
            throw FulfilmentException::nothingReturnable($returnable);
        }

        // Recorded as a REQUEST. It has no financial effect until the supplier
        // approves it - returned_quantity (cumulative approved) is untouched.
        $orderItem->update([
            'status' => 'delete_pending',
            'pending_return_quantity' => $quantity,
        ]);

        $supplierId = $orderItem->supplier_id ?? $orderItem->product->user_id;

        $orderItem->notificationsCenters()->create([
            'user_id' => $supplierId,
            'title' => 'طلب حذف منتج من الطلب',
            'message' => "⚠️ قام الطبيب {$user->name} بطلب حذف المنتج {$orderItem->product->name} من الطلب رقم #{$orderItem->order_id}.<br>⏳ الحالة: بانتظار تأكيدك.",
            'type' => 'cart',
            'color' => 'orange',
        ]);

        // إشعار FCM (اختياري)
        $tokens = FcmToken::where('user_id', $supplierId)->pluck('fcm_token');
        $firebase = new FirebaseService;
        foreach ($tokens as $token) {
            $firebase->send(
                'طلب حذف منتج من الطلب',
                'قام الطبيب '.$user->name.' بطلب حذف المنتج '.$orderItem->product->name.' من الطلب رقم #'.$orderItem->order_id,
                $token,
                '/orders/current-orders'
            );
        }

        return $orderItem;
    }
}
