<?php

namespace App\Http\Controllers\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\ProductMasterStoreRequest;
use App\Http\Requests\Catalogue\ProductMasterUpdateRequest;
use App\Http\Resources\Catalogue\ProductMasterResource;
use App\Models\Catalogue\ProductMaster;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * STAGE 7 — the canonical product catalogue.
 *
 * Two audiences, one controller:
 *
 *   index/show   everyone. Suppliers and doctors must be able to SEARCH the
 *                catalogue in order to select from it — that is the mechanism
 *                by which they stop inventing duplicate product definitions.
 *   write        Super Admin only, enforced by ProductMasterPolicy.
 *
 * `index` supports `search` and `active` so the frontend can build whatever
 * picker it likes (dropdown, autocomplete, modal) without the backend caring
 * which. The business rule — selection rather than invention — is enforced
 * server-side by InventoryRequest requiring a product_master_id, not by the UI.
 */
class ProductMasterController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $this->authorize('viewAny', ProductMaster::class);

            $catalogue = ProductMaster::query()
                ->with('category')
                ->withCount('products')
                ->search($request->query('search'))
                ->when(
                    // Default to active-only: a picker should not offer
                    // withdrawn products. Super Admin can ask for everything.
                    $request->boolean('include_inactive') && $request->user()->isSuperAdmin(),
                    fn ($q) => $q,
                    fn ($q) => $q->active()
                )
                ->orderBy('name')
                ->paginate((int) $request->query('per_page', 15));

            return $this->paginatedResponse(ProductMasterResource::collection($catalogue), $catalogue);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً', 422);
        }
    }

    public function show($id)
    {
        try {
            $productMaster = ProductMaster::with('category')->findOrFail($id);

            $this->authorize('view', $productMaster);

            return $this->successResponse(new ProductMasterResource($productMaster));
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المنتج غير موجود', 404);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً', 422);
        }
    }

    public function store(ProductMasterStoreRequest $request)
    {
        try {
            $this->authorize('create', ProductMaster::class);

            $data = $request->validated();

            if ($request->hasFile('img')) {
                $data['img'] = $request->file('img')->store('product-masters', 'public');
            }

            $productMaster = new ProductMaster($data);

            // Assigned explicitly rather than merged into the mass-assignment
            // array: `created_by` is deliberately NOT fillable, so a client
            // cannot claim authorship by posting the field.
            $productMaster->created_by = $request->user()->id;
            $productMaster->save();

            return $this->createSuccessResponse(
                'تم إنشاء المنتج الرئيسي بنجاح',
                new ProductMasterResource($productMaster)
            );
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً', 422);
        }
    }

    public function update(ProductMasterUpdateRequest $request, $product_master)
    {
        try {
            $productMaster = ProductMaster::findOrFail($product_master);

            $this->authorize('update', $productMaster);

            $data = $request->validated();

            if ($request->hasFile('img')) {
                $previous = $productMaster->getRawOriginal('img');

                if ($previous && Storage::disk('public')->exists($previous)) {
                    Storage::disk('public')->delete($previous);
                }

                $data['img'] = $request->file('img')->store('product-masters', 'public');
            }

            $productMaster->update($data);

            return $this->createSuccessResponse(
                'تم تحديث المنتج الرئيسي بنجاح',
                new ProductMasterResource($productMaster->fresh('category')),
                200
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المنتج غير موجود', 404);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً', 422);
        }
    }

    /**
     * Withdraw a product from circulation, or bring it back.
     *
     * Deliberately not a delete. A canonical product is referenced by order
     * history, which Stage 5 protected with RESTRICT; archiving is how a
     * discontinued product leaves the picker without erasing what it means on
     * past orders. The broader soft-delete strategy is Stage 8's.
     */
    public function toggleActive(Request $request, $product_master)
    {
        try {
            $productMaster = ProductMaster::findOrFail($product_master);

            $this->authorize('archive', $productMaster);

            $productMaster->update(['is_active' => ! $productMaster->is_active]);

            return $this->createSuccessResponse(
                $productMaster->is_active ? 'تم تفعيل المنتج' : 'تم إلغاء تفعيل المنتج',
                new ProductMasterResource($productMaster),
                200
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المنتج غير موجود', 404);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً', 422);
        }
    }
}
