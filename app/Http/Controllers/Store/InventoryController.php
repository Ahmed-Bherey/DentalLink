<?php

namespace App\Http\Controllers\Store;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Exports\ProductsExport;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Services\Store\InventoryService;
use App\Http\Requests\Store\InventoryRequest;
use App\Http\Requests\Store\MultiDeleteRequest;
use App\Http\Resources\Store\InventoryResource;
use App\Http\Requests\Store\InventoryUpdateRequest;

class InventoryController extends Controller
{
    use ApiResponse;
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    // عرض قائمة المنتجات
    public function index()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $search = request()->query('search');

            $inventories = $this->inventoryService->getAll($user, $perPage, $search);

            return $this->paginatedResponse(
                InventoryResource::collection($inventories),
                $inventories
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // اضافة منتج جديد
    public function Store(InventoryRequest $request)
    {
        try {
            $inventory = $this->inventoryService->create($request->validated());
            return $this->createSuccessResponse(
                'تم إضافة المنتج بنجاح',
                new InventoryResource($inventory['product']),
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة مرة أخرى',
                422
            );
        }
    }

    // تحديث المنتج
    public function update(InventoryUpdateRequest $request, $id)
    {
        try {
            $inventory = $this->inventoryService->update($id, $request->validated());
            return $this->createSuccessResponse(
                'تم تحديث المنتج بنجاح',
                new InventoryResource($inventory['product']),
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما أثناء التحديث. برجاء المحاولة مرة أخرى',
                422
            );
        }
    }

    // حذف منتج واحد
    public function destroy($id)
    {
        try {
            $this->inventoryService->delete($id);
            return $this->successResponse('تم حذف المنتج بنجاح');
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما أثناء الحذف. برجاء المحاولة مرة أخرى',
                422
            );
        }
    }

    // حذف مجموعة منتجات
    public function multiDestroy(MultiDeleteRequest $request)
    {
        try {
            $this->inventoryService->multiDelete($request->validated()['ids']);

            return $this->successResponse('تم حذف المنتجات بنجاح');
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما أثناء الحذف. برجاء المحاولة مرة أخرى.',
                422
            );
        }
    }

    // عرض قائمة منتجات كل الموردين للطبيب
    public function allSuppliersProducts()
    {
        try {
            $inventories = $this->inventoryService->getAllSuppliersProducts();
            return $this->successResponse(
                InventoryResource::collection($inventories),
                200,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // البحث
    public function search()
    {
        try {
            $user = request()->user();
            $search = request()->query('search');

            $results = $this->inventoryService->search($user, $search);

            return $this->successResponse(
                InventoryResource::collection($results),
                200,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء البحث. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // تحميل المنتجات فى صيغة اكسيل
    public function exportExcel()
    {
        try {
            $user = request()->user();
            return Excel::download(new ProductsExport($user), 'products.xlsx');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء تحميل البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
