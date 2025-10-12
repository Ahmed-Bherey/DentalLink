<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Financial\Order;
use App\Http\Controllers\Controller;
use App\Services\Financial\CartService;
use App\Http\Resources\Financial\CartResource;
use App\Http\Requests\Financial\CartStoreRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CartController extends Controller
{
    use ApiResponse;
    protected $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    // عرض السلة
    public function index()
    {
        try {
            $doctor = request()->user();
            $perPage = request()->get('per_page', 10);
            $carts = $this->cartService->index($doctor, $perPage);
            return $this->paginatedResponse(
                CartResource::collection($carts),
                $carts,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // اضافة السلة
    public function store(CartStoreRequest $request)
    {
        try {
            $doctor = request()->user();
            $cart = $this->cartService->store($doctor, $request->validated());
            return $this->successResponseWithId(
                'تم إضافة المنتج بنجاح',
                $cart->id,
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

    // تحديث عنصر في السلة
    public function update(CartStoreRequest $request, $id)
    {
        try {
            $cart = $this->cartService->update($id, $request->validated());

            return $this->successResponseWithId(
                'تم تحديث المنتج في السلة بنجاح',
                $cart->id
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('العنصر غير موجود في السلة.', 404);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ليس لديك صلاحية لتعديل هذا العنصر.',
            ], 403);
        } catch (Exception $e) {
            return $this->errorResponse(
                'حدث خطأ أثناء تحديث العنصر. الرجاء المحاولة لاحقاً.',
                500
            );
        }
    }

    // حذف عنصر من السلة
    public function destroy($id)
    {
        try {
            $this->cartService->destroy($id);

            return $this->successResponse('تم حذف المنتج من السلة بنجاح');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('العنصر غير موجود في السلة.', 404);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ليس لديك صلاحية لحذف هذا العنصر.',
            ], 403);
        } catch (Exception $e) {
            return $this->errorResponse(
                'حدث خطأ أثناء حذف العنصر. الرجاء المحاولة لاحقاً.',
                500
            );
        }
    }
}
