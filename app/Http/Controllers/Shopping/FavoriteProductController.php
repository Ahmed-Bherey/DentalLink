<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shopping\FavoriteProductResource;
use App\Models\Shopping\FavoriteProduct;
use App\Services\Shopping\FavoriteProductService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;

class FavoriteProductController extends Controller
{
    use ApiResponse;

    protected $favoriteProductService;

    public function __construct(FavoriteProductService $favoriteProductService)
    {
        $this->favoriteProductService = $favoriteProductService;
    }

    // عرض قائمة المفضلة
    public function index()
    {
        try {
            $this->authorize('viewAny', FavoriteProduct::class);

            $doctor = request()->user();
            $perPage = request()->get('per_page', 10);
            $favoriteProducts = $this->favoriteProductService->index($doctor, $perPage);

            return $this->paginatedResponse(
                FavoriteProductResource::collection($favoriteProducts),
                $favoriteProducts,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // اضافة الى المفضلة
    public function addToFavorite($product_id)
    {
        try {
            $this->authorize('create', FavoriteProduct::class);

            $doctor = request()->user();
            $perPage = request()->get('per_page', 10);
            $favoriteProduct = $this->favoriteProductService->addToFavorite($doctor, $product_id, $perPage);

            return $this->successResponseWithId(
                'تمت الإضافة إلى المفضلة بنجاح',
                $favoriteProduct->id,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // حذف منتج من المفضلة
    public function removeFromFavorite($product_id)
    {
        try {
            $favorite = FavoriteProduct::findOrFail($product_id);

            $this->authorize('delete', $favorite);

            $this->favoriteProductService->removeFromFavorite($favorite);

            return $this->successResponse('تم حذف المنتج من المفضلة بنجاح.');
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                $e->getMessage() ?? 'حدث خطأ أثناء محاولة حذف المنتج من المفضلة.',
                422
            );
        }
    }
}
