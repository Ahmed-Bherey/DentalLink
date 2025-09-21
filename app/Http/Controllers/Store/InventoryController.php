<?php

namespace App\Http\Controllers\Store;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Store\InventoryService;
use App\Http\Requests\Store\InventoryRequest;
use App\Http\Resources\Store\InventoryResource;

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
            $inventories = $this->inventoryService->getAll($user);
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

    // اضافة منتج جديد
    public function Store(InventoryRequest $request)
    {
        try {
            $inventory = $this->inventoryService->create($request->validated());
            return $this->successResponseWithId(
                'تم إضافة المنتج بنجاح',
                $inventory['product']?->id
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة مرة أخرى',
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
}
