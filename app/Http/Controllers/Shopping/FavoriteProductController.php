<?php

namespace App\Http\Controllers\Shopping;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\Shopping\FavoriteProductResource;
use App\Services\Shopping\FavoriteProductService;

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
            $doctor = request()->user();
            $perPage = request()->get('per_page', 10);
            $favoriteProducts = $this->favoriteProductService->index($doctor, $perPage);
            return $this->paginatedResponse(
                FavoriteProductResource::collection($favoriteProducts),
                $favoriteProducts,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
