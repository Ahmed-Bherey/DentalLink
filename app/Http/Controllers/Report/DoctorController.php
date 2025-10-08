<?php

namespace App\Http\Controllers\Report;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\Report\SupplierResource;
use App\Services\Report\DoctorService;

class DoctorController extends Controller
{
    use ApiResponse;
    protected $doctorService;

    public function __construct(DoctorService $doctorService)
    {
        $this->doctorService = $doctorService;
    }

    public function getAllsuppliers()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 10);

            $doctors = $this->doctorService->getAllsuppliers($user, $perPage);

            return $this->paginatedResponse(
                SupplierResource::collection($doctors),
                $doctors
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب قائمة الأطباء. برجاء المحاولة لاحقاً.',
                422
            );
        }
    }
}
