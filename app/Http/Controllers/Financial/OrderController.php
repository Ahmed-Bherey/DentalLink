<?php

namespace App\Http\Controllers\Financial;

use App\Exceptions\Financial\FulfilmentException;
use App\Exceptions\Financial\PaymentException;
use App\Exports\DeliveredOrdersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\OrderRequest;
use App\Http\Requests\Financial\OrderUpdateRequest;
use App\Http\Requests\Financial\UpdateItemRequest;
use App\Http\Requests\Financial\UpdateStatusRequest;
use App\Http\Resources\Financial\OrderResource;
use App\Models\Financial\Order;
use App\Models\Financial\OrderItem;
use App\Services\Financial\OrderService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class OrderController extends Controller
{
    use ApiResponse;

    protected $orderService;

    public function __construct(OrderService $OrderService)
    {
        $this->orderService = $OrderService;
    }

    // عرض قائمة الطلبات للمورد والطبيب
    /**
     * A single order, in full.
     *
     * STAGE 7.5: added because the only way to reach one order was to page
     * through a list and filter client-side, which cannot back a deep link to
     * an order-details screen.
     *
     * Authorization is OrderPolicy::view — the ordering doctor, or a supplier
     * who owns at least one line. It denies with 403 rather than 404, matching
     * the rest of the order surface: both parties already know the order
     * exists, so hiding it would only produce misleading errors for a genuine
     * counterparty. (Products, carts and receipts use 404 because their ids
     * must not be enumerable; that distinction is deliberate and preserved.)
     *
     * Relations are eager-loaded because OrderItemResource reaches for the
     * product, its category and the supplier on every line.
     */
    public function show($id)
    {
        try {
            $order = Order::with([
                'doctor',
                'orderItems.product.category',
                'orderItems.product.user',
                'orderItems.supplier',
            ])->findOrFail($id);

            $this->authorize('view', $order);

            return $this->successResponse(new OrderResource($order));
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('الطلب غير موجود', 404);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    public function indexForTypes()
    {
        try {
            $this->authorize('viewAny', Order::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $supplierOrders = $this->orderService->indexForTypes($user, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($supplierOrders),
                $supplierOrders,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    public function refundOrder()
    {
        try {
            $this->authorize('viewAny', Order::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $supplierOrders = $this->orderService->getRefundOrder($user, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($supplierOrders),
                $supplierOrders,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // عرض قائمة الطلبات المسلمة للمورد والطبيب
    public function deliveredOrders()
    {
        try {
            $this->authorize('viewAny', Order::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $supplierOrders = $this->orderService->getDeliveredOrders($user, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($supplierOrders),
                $supplierOrders,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // انشاء طلب
    public function store(OrderRequest $request)
    {
        try {
            $this->authorize('create', Order::class);
            $user = request()->user();
            $order = $this->orderService->store($user, $request->validated());

            return $this->createSuccessResponse(
                'تم إضافة المنتج بنجاح',
                new OrderResource($order),
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة مرة أخرى',
                422
            );
        }
    }

    // تحديث حالة الطلب
    public function updateStatus(UpdateStatusRequest $request, $order_id)
    {
        try {
            $user = request()->user();

            $this->authorize('fulfil', Order::findOrFail($order_id));

            $order = $this->orderService->updateStatus($user, $order_id, $request->validated());

            return $this->successResponseWithoutData(
                'تم تحديث حالة الطلب بنجاح',
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(
                'الطلب غير موجود.',
                404
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة مرة أخرى',
                422
            );
        }
    }

    // تحديث بيانات الطلب
    public function update(OrderUpdateRequest $request, $id)
    {
        try {
            $order = Order::findOrFail($id);

            $this->authorize('update', $order);

            if ($order->status != 'pending' || $order->status != 'preparing') {
                return $this->errorResponse('عفوا, لم يعد بالامكان تعديل بيانات الطلب.', 403);
            }

            $order = $this->orderService->update($order, $request->validated());

            return $this->successResponseWithId(
                'تم تحديث الطلب بنجاح',
                $order->id
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('حدث خطأ أثناء التحديث.', 422);
        }
    }

    public function updateItemStatus(Request $request, $orderItem_id)
    {
        $validated = $request->validate([
            'status' => 'required|in:confirmed,rejected',
        ]);

        try {
            $orderItem = OrderItem::findOrFail($orderItem_id);

            $this->authorize('decideReturn', $orderItem);

            $result = $this->orderService->updateItemStatus($validated, $orderItem, $request->user());

            return $this->successResponse($result['message']);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المنتج غير موجود.', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('حدث خطأ أثناء تحديث الحالة.', 422);
        }
    }

    // حذف منتج من الطلب
    public function deleteItem(Request $request, $orderItem_id)
    {
        $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);
        try {
            $user = request()->user();
            $deleteItem = OrderItem::findOrFail($orderItem_id);

            $this->authorize('requestReturn', $deleteItem);

            $this->orderService->requestDeleteItem($request->quantity, $user, $deleteItem);

            return $this->successResponse('تم حذف المنتج بنجاح');
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('حدث خطأ أثناء الحذف.', 422);
        }
    }

    // تعديل منتج من الطلب
    public function UpdateItem(UpdateItemRequest $request, $orderItem_id)
    {
        try {
            $orderItem = OrderItem::findOrFail($orderItem_id);

            $this->authorize('update', $orderItem);

            $this->orderService->UpdateItem($orderItem, $request->validated(), $request->user());

            return $this->createSuccessResponse('تم تحديث المنتج بنجاح', $orderItem->order->total_order_price, 200);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('حدث خطأ أثناء التحديث.', 422);
        }
    }

    // حذف الطلب
    public function destroy($id)
    {
        try {
            $user = request()->user();
            $order = Order::findOrFail($id);

            $this->authorize('delete', $order);

            $this->orderService->requestDelete($user, $order);

            return $this->successResponse('تم حذف الطلب بنجاح');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('الطلب غير موجود.', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('حدث خطأ أثناء الحذف.', 422);
        }
    }

    public function returnItem(Request $request, $orderItemId)
    {
        $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $orderItem = OrderItem::findOrFail($orderItemId);

            $this->authorize('requestReturn', $orderItem);

            $this->orderService->requestDeleteItem(
                $request->quantity,
                $request->user(),
                $orderItem,
            );

            return $this->successResponseWithoutData('تم استرجاع المنتج بنجاح');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('العنصر غير موجود', 404);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (FulfilmentException $e) {
            // Order-state refusals ("not delivered yet", "nothing left to
            // return") carry an actionable reason and a 422. Without this they
            // fell into the generic catch below and surfaced as a 500.
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse('حدث خطأ أثناء الاسترجاع', 500);
        }
    }

    public function exportDeliveredOrders()
    {
        $this->authorize('viewAny', Order::class);

        $user = request()->user();
        $orders = $this->orderService->getDeliveredOrders($user);

        return Excel::download(new DeliveredOrdersExport($orders), 'delivered_orders.xlsx');
    }

    // البحث باسم الطبيب او حالة الطلب
    public function searchOrders()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 10);

            $this->authorize('viewAny', Order::class);

            $orders = $this->orderService->searchOrders($user, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($orders),
                $orders
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
        } catch (FulfilmentException $e) {
            // Same reasoning for order-state refusals ("already fulfilled",
            // "insufficient stock", "not delivered yet").
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء البحث. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
