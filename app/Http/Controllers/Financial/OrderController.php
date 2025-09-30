<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Financial\Order;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\DeliveredOrdersExport;
use App\Services\Financial\OrderService;
use App\Http\Requests\Financial\OrderRequest;
use App\Http\Resources\Financial\OrderResource;
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
            $perPage = request()->get('per_page', 3);
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

    // عرض قائمة الطلبات المسلمة للمورد والطبيب
    public function deliveredOrders()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 3);
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
        try {
            $this->authorize('create', Order::class);
            $user = request()->user();
            $order = $this->orderService->store($user, $request->validated());
            return $this->createSuccessResponse(
                'تم إضافة المنتج بنجاح',
                new OrderResource($order),
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

    // تحديث بيانات الطلب
    public function update(OrderUpdateRequest $request, $id)
    {
        try {
            $order = Order::findOrFail($id);

            $this->authorize('update', $order);

            if ($order->status != 'pending') {
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

    // حذف الطلب
    public function destroy($id)
    {
        try {
            $order = Order::findOrFail($id);
            //$this->authorize('delete', $order);

            $this->orderService->delete($order);
            return $this->successResponse('تم حذف الطلب بنجاح');
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'عفوا، ليس لديك صلاحية لحذف هذا الطلب.',
            ], 403);
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
            $this->orderService->returnOrderItem(
                $request->user(),
                $orderItemId,
                $request->quantity
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
