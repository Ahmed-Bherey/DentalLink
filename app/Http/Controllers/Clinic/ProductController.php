<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Resources\Store\InventoryResource;
use App\Models\Store\Product;
use App\Services\Clinic\ProductService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;

class ProductController extends Controller
{
    use ApiResponse;

    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    // البحث
    public function search()
    {
        try {
            $this->authorize('viewAny', Product::class);

            $user = request()->user();

            $search = request()->query('search');
            $minPrice = request()->query('min_price');
            $maxPrice = request()->query('max_price');
            $categoryId = request()->query('category_id');

            $results = $this->productService->search($user, $search, $minPrice, $maxPrice, $categoryId);

            return $this->successResponse(
                InventoryResource::collection($results),
                200,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء البحث. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
