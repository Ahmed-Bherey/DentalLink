<?php

namespace App\Http\Controllers\Clinic;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Clinic\ProductService;
use App\Http\Resources\Store\InventoryResource;

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
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء البحث. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
