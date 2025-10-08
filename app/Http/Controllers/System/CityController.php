<?php

namespace App\Http\Controllers\System;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\System\CityServices;
use App\Http\Resources\System\CityResource;
use App\Http\Requests\System\CityStoreRequest;
use App\Http\Requests\System\CityUpdateRequest;
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
            $city = $this->cityServices->show($id);
            return $this->successResponse(new CityResource($city), 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المدينة غير موجودة.', 404);
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
            $user = $request->user();
            $city = $this->cityServices->create($user, $request->validated());

            return $this->createSuccessResponse('تم إنشاء المدينة بنجاح.', new CityResource($city));
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
            $user = $request->user();
            $city = $this->cityServices->update($user, $id, $request->validated());

            return $this->successResponse('تم تحديث المدينة بنجاح.', 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المدينة غير موجودة.', 404);
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
            $user = request()->user();
            $this->cityServices->delete($user, $id);

            return $this->successResponse('تم حذف المدينة بنجاح.', 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('المدينة غير موجودة.', 404);
        } catch (Exception $e) {
            return $this->errorResponse('عذراً، حدث خطأ أثناء الحذف.', 422);
        }
    }
}
