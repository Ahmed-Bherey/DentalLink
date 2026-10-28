<?php

namespace App\Http\Controllers\Store;

use App\Exports\ProductsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\InventoryRequest;
use App\Http\Requests\Store\InventoryUpdateRequest;
use App\Http\Requests\Store\MultiDeleteRequest;
use App\Http\Resources\Store\InventoryResource;
use App\Imports\ProductsImport;
use App\Models\FcmToken;
use App\Models\Store\Product;
use App\Services\Store\InventoryService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

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
            $this->authorize('viewAny', Product::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $search = request()->query('search');

            $inventories = $this->inventoryService->getAll($user, $perPage, $search);

            return $this->paginatedResponse(
                InventoryResource::collection($inventories),
                $inventories
            );
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // عرض منتج واحد
    public function show($id)
    {
        try {
            $product = $this->inventoryService->getById($id);

            if (! $product) {
                return $this->errorResponse('المنتج غير موجود', 404);
            }

            // ProductPolicy::view denies as NOT FOUND, so a non-owner cannot tell
            // an existing product from a missing one.
            $this->authorize('view', $product);

            return $this->successResponse(new InventoryResource($product));
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب المنتج. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // اضافة منتج جديد
    public function Store(InventoryRequest $request)
    {
        try {
            $this->authorize('create', Product::class);

            $inventory = $this->inventoryService->create($request->validated());

            return $this->createSuccessResponse(
                'تم إضافة المنتج بنجاح',
                new InventoryResource($inventory['product']),
            );
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
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
            $product = Product::findOrFail($id);

            $this->authorize('update', $product);

            $inventory = $this->inventoryService->update($product, $request->validated());

            return $this->createSuccessResponse(
                'تم تحديث المنتج بنجاح',
                new InventoryResource($inventory['product']),
            );
        } catch (ModelNotFoundException $e) {
            // Owner-scoped lookup: not found OR not owned by the caller.
            return $this->errorResponse('المنتج غير موجود', 404);
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
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
            $product = Product::findOrFail($id);

            $this->authorize('delete', $product);

            $this->inventoryService->delete($product);

            return $this->successResponse('تم حذف المنتج بنجاح');
        } catch (ModelNotFoundException $e) {
            // Owner-scoped lookup: not found OR not owned by the caller.
            return $this->errorResponse('المنتج غير موجود', 404);
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
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
            $ids = array_unique($request->validated()['ids']);
            $products = Product::whereIn('id', $ids)->get();

            if ($products->count() !== count($ids)) {
                return $this->errorResponse('أحد المنتجات المحددة غير موجود.', 404);
            }

            // Authorize EVERY product before deleting ANY, so the batch is all
            // or nothing. A single unauthorized id refuses the whole request.
            foreach ($products as $product) {
                $this->authorize('delete', $product);
            }

            $this->inventoryService->multiDelete($products);

            return $this->successResponse('تم حذف المنتجات بنجاح');
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما أثناء الحذف. برجاء المحاولة مرة أخرى.',
                422
            );
        }
    }

    // عرض قائمة منتجات كل الموردين للطبيب
    public function allSuppliersProducts(Request $request)
    {
        try {
            $this->authorize('browseCatalogue', Product::class);

            $user = $request->user();
            $filters = $request->only(['search', 'category_id', 'sort']);
            $inventories = $this->inventoryService->getAllSuppliersProducts($filters, $user);

            return $this->paginatedResponse(
                InventoryResource::collection($inventories),
                $inventories
            );
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
        } catch (\Exception $e) {
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
            $this->authorize('viewAny', Product::class);

            $user = request()->user();
            $search = request()->query('search');

            $results = $this->inventoryService->search($user, $search);

            return $this->successResponse(
                InventoryResource::collection($results),
                200,
            );
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
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
            $this->authorize('viewAny', Product::class);

            $user = request()->user();

            return Excel::download(new ProductsExport($user), 'products.xlsx');
        } catch (AuthorizationException $e) {
            // Authorization failures render centrally (403, or 404 for
            // denyAsNotFound policies). Never swallow them below.
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء تحميل البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // استيراد ملف اكسيل لرفع منتجات
    public function importProducts(Request $request)
    {
        $this->authorize('create', Product::class);

        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        Excel::queueImport(
            new ProductsImport($request->user()->id),
            $request->file('file')
        );

        return response()->json([
            'message' => 'تم بدء استيراد المنتجات في الخلفية',
        ]);
    }

    public function fcmtokende(Request $request)
    {
        $user = $request->user();
        // جلب كل توكنات FCM للمستخدم
        $fcmTokens = FcmToken::where('user_id', $user->id)->get();

        // حذف كل توكن من الـ FCM
        foreach ($fcmTokens as $token) {
            $token->delete();
        }
    }
}
