<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Financial\Order;
use App\Http\Controllers\Controller;
use App\Services\Financial\OrderService;
use App\Http\Requests\Financial\OrderRequest;
use App\Http\Resources\Financial\OrderResource;
use Illuminate\Auth\Access\AuthorizationException;
use App\Http\Requests\Financial\UpdateStatusRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OrderController extends Controller
{
    use ApiResponse;
    protected $orderService;

    public function __construct(OrderService $OrderService)
    {
        $this->orderService = $OrderService;
    }

    // عرض قائمة الطلبات للمورد
    public function indexForSupplier()
    {
        try {
            $user = request()->user();
            $supplierOrders = $this->orderService->getAllForSupplies($user);
            return $this->successResponse(
                OrderResource::collection($supplierOrders),
                200,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // عرض قائمة الطلبات المسلمة للمورد
    public function deliveredOrdersForSupplier()
    {
        try {
            $user = request()->user();
            $supplierOrders = $this->orderService->getDeliveredOrdersForSupplier($user);
            return $this->successResponse(
                OrderResource::collection($supplierOrders),
                200,
            );
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
            return $this->successResponseWithId(
                'تم إضافة المنتج بنجاح',
                $order->id
            );
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'عفوا، ليس لديك صلاحية لإنشاء الطلب.',
            ], 403);
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
            $order = $this->orderService->updateStatus($user, $order_id, $request->validated());
            return $this->successResponseWithoutData(
                'تم تحديث حالة الطلب بنجاح',
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(
                'الطلب غير موجود.',
                404
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة مرة أخرى',
                422
            );
        }
    }
}
