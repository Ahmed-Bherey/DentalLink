<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\CartStoreRequest;
use App\Http\Requests\Financial\CartUpdateRequest;
use App\Http\Resources\Financial\CartResource;
use App\Models\Financial\Cart;
use App\Services\Financial\CartService;
use App\Traits\ApiResponse;
use Exception;
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
            $this->authorize('viewAny', Cart::class);

            $doctor = request()->user();
            $perPage = request()->get('per_page', 10);
            $carts = $this->cartService->index($doctor, $perPage);

            return $this->paginatedResponse(
                CartResource::collection($carts),
                $carts,
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

    // اضافة السلة
    public function store(CartStoreRequest $request)
    {
        try {
            $this->authorize('create', Cart::class);

            $doctor = request()->user();
            $cart = $this->cartService->store($doctor, $request->validated());

            return $this->successResponseWithId(
                'تم إضافة المنتج بنجاح',
                $cart->id,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة مرة أخرى',
                422
            );
        }
    }

    // تحديث عنصر في السلة
    public function update(CartUpdateRequest $request, $id)
    {
        try {
            $cart = Cart::findOrFail($id);

            $this->authorize('update', $cart);

            $cart = $this->cartService->update($cart, $request->validated());

            return $this->successResponseWithId(
                'تم تحديث المنتج في السلة بنجاح',
                $cart->id
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('العنصر غير موجود في السلة.', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
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
            $cart = Cart::findOrFail($id);

            $this->authorize('delete', $cart);

            $this->cartService->destroy($cart);

            return $this->successResponse('تم حذف المنتج من السلة بنجاح');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('العنصر غير موجود في السلة.', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse(
                'حدث خطأ أثناء حذف العنصر. الرجاء المحاولة لاحقاً.',
                500
            );
        }
    }
}
