<?php

namespace App\Http\Controllers\Report;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\Report\DoctorResource;
use App\Services\Report\SupplierService;

class SupplierController extends Controller
{
    use ApiResponse;
    protected $supplierService;

    public function __construct(SupplierService $supplierService)
    {
        $this->supplierService = $supplierService;
    }

    public function getAllDoctors()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 10);

            $doctors = $this->supplierService->getAllDoctors($user, $perPage);

            return $this->paginatedResponse(
                DoctorResource::collection($doctors),
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
