<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\CityStoreRequest;
use App\Http\Requests\System\CityUpdateRequest;
use App\Http\Resources\System\CityResource;
use App\Models\General\City;
use App\Services\System\CityServices;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CityController extends Controller
{
    use ApiResponse;

    protected $cityServices;

    public function __construct(CityServices $cityServices)
    {
        $this->cityServices = $cityServices;
    }

    /**
     * عرض جميع المدن
     */
    public function index()
    {
        try {
            $cities = $this->cityServices->index();

            return $this->successResponse(CityResource::collection($cities), 200);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ أثناء جلب البيانات.', 422);
        }
    }

    /**
     * عرض مدينة محددة
     */
    public function show($id)
    {
        try {
            $city = City::findOrFail($id);

            $this->authorize('view', $city);

            return $this->successResponse(new CityResource($city), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المدينة غير موجودة.', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ أثناء جلب المدينة.', 422);
        }
    }

    /**
     * إنشاء مدينة جديدة
     */
    public function store(CityStoreRequest $request)
    {
        try {
            $this->authorize('create', City::class);

            $user = $request->user();
            $city = $this->cityServices->create($user, $request->validated());

            return $this->createSuccessResponse('تم إنشاء المدينة بنجاح.', new CityResource($city));
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ أثناء إنشاء المدينة.', 422);
        }
    }

    /**
     * تحديث مدينة
     */
    public function update(CityUpdateRequest $request, $id)
    {
        try {
            $city = City::findOrFail($id);

            $this->authorize('update', $city);

            $city = $this->cityServices->update($city, $request->validated());

            return $this->successResponse('تم تحديث المدينة بنجاح.', 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المدينة غير موجودة.', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ أثناء التحديث.', 422);
        }
    }

    /**
     * حذف مدينة
     */
    public function destroy($id)
    {
        try {
            $city = City::findOrFail($id);

            $this->authorize('delete', $city);

            $this->cityServices->delete($city);

            return $this->successResponse('تم حذف المدينة بنجاح.', 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المدينة غير موجودة.', 404);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ أثناء الحذف.', 422);
        }
    }
}
