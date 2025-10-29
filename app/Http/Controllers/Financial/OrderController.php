<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Financial\Order;
use App\Models\Financial\OrderItem;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\DeliveredOrdersExport;
use App\Models\Financial\OrderExpense;
use App\Services\Financial\OrderService;
use App\Http\Requests\Financial\OrderRequest;
use App\Http\Resources\Financial\OrderResource;
use App\Http\Requests\Financial\UpdateItemRequest;
use Illuminate\Auth\Access\AuthorizationException;
use App\Http\Requests\Financial\OrderUpdateRequest;
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

    // عرض قائمة الطلبات للمورد والطبيب
    public function indexForTypes()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $supplierOrders = $this->orderService->indexForTypes($user, $perPage);
            return $this->paginatedResponse(
                OrderResource::collection($supplierOrders),
                $supplierOrders,
            );
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
            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $supplierOrders = $this->orderService->getRefundOrder($user, $perPage);
            return $this->paginatedResponse(
                OrderResource::collection($supplierOrders),
                $supplierOrders,
            );
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
            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $supplierOrders = $this->orderService->getDeliveredOrders($user, $perPage);
            return $this->paginatedResponse(
                OrderResource::collection($supplierOrders),
                $supplierOrders,
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
        //try {
        $this->authorize('create', Order::class);
        $user = request()->user();
        $order = $this->orderService->store($user, $request->validated());
        return $this->createSuccessResponse(
            'تم إضافة المنتج بنجاح',
            new OrderResource($order),
        );
        // } catch (AuthorizationException $e) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'عفوا، ليس لديك صلاحية لإنشاء الطلب.',
        //     ], 403);
        // } catch (Exception $e) {
        //     return $this->errorResponse(
        //         'عذراً، حدث خطأ ما. برجاء المحاولة مرة أخرى',
        //         422
        //     );
        // }
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
            return response()->json([
                'success' => false,
                'message' => 'عفوا، ليس لديك صلاحية لتحديث هذا الطلب.',
            ], 403);
        } catch (Exception $e) {
            return $this->errorResponse('حدث خطأ أثناء التحديث.', 422);
        }
    }

    public function updateItemStatus(Request $request, $orderItem_id)
    {
        try {
            $orderItem = OrderItem::findOrFail($orderItem_id);

            $validated = $request->validate([
                'status' => 'required|in:confirmed,rejected',
            ]);

            $result = $this->orderService->updateItemStatus($validated, $orderItem);

            return $this->successResponse($result['message']);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المنتج غير موجود.', 404);
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

            $this->orderService->requestDeleteItem($request->quantity, $user, $deleteItem);
            return $this->successResponse('تم حذف المنتج بنجاح');
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'عفوا، ليس لديك صلاحية لحذف هذا المنتج.',
            ], 403);
        } catch (Exception $e) {
            return $this->errorResponse('حدث خطأ أثناء الحذف.', 422);
        }
    }

    // تعديل منتج من الطلب
    public function UpdateItem(UpdateItemRequest $request, $orderItem_id)
    {
        try {
            $orderItem = OrderItem::findOrFail($orderItem_id);

            $this->orderService->UpdateItem($orderItem, $request->validated());

            return $this->createSuccessResponse('تم تحديث المنتج بنجاح', $orderItem->order->total_order_price, 200);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'عفوا، ليس لديك صلاحية لتحديث هذا المنتج.',
            ], 403);
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
            //$this->authorize('delete', $order);

            $this->orderService->requestDelete($user, $order);
            return $this->successResponse('تم حذف الطلب بنجاح');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('الطلب غير موجود.', 404);
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
            $this->orderService->requestDeleteItem(
                $request->quantity,
                $request->user(),
                $orderItemId,
            );

            return $this->successResponseWithoutData("تم استرجاع المنتج بنجاح");
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse("العنصر غير موجود", 404);
        } catch (AuthorizationException $e) {
            return $this->errorResponse("لا تملك صلاحية استرجاع هذا العنصر", 403);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse("حدث خطأ أثناء الاسترجاع", 500);
        }
    }

    public function exportDeliveredOrders()
    {
        $user = request()->user();
        $orders = $this->orderService->getDeliveredOrders($user);
        return Excel::download(new DeliveredOrdersExport($orders), 'delivered_orders.xlsx');
    }

    public function showexpen()
    {
        $user = request()->user();
        $expense = OrderExpense::where('doctor_id', $user->id)
            ->where('supplier_id', 2)
            ->first();
        dd($expense);
    }

    // البحث باسم الطبيب او حالة الطلب
    public function searchOrders()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 10);

            $orders = $this->orderService->searchOrders($user, $perPage);

            return $this->paginatedResponse(
                OrderResource::collection($orders),
                $orders
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء البحث. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    public function backupDatabase()
    {
        $dbHost     = config('database.connections.mysql.host');
        $dbPort     = config('database.connections.mysql.port');
        $dbName     = config('database.connections.mysql.database');
        $dbUser     = config('database.connections.mysql.username');
        $dbPassword = config('database.connections.mysql.password');

        $fileName   = "db-backup-" . now()->format('Y-m-d-H-i-s') . ".sql";
        $backupPath = storage_path("app/backups/{$fileName}");

        // escape password
        $command = "mysqldump -h {$dbHost} -P {$dbPort} -u {$dbUser} -p\"{$dbPassword}\" {$dbName} > \"{$backupPath}\"";

        $result = null;
        $output = null;
        exec($command, $output, $result);

        if ($result === 0 && file_exists($backupPath)) {
            return response()->download($backupPath)->deleteFileAfterSend();
        } else {
            return response()->json(['message' => 'فشل في إنشاء النسخة الاحتياطية'], 500);
        }
    }
}
