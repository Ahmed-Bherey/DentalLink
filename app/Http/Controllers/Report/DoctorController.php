<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Http\Resources\Report\SupplierResource;
use App\Models\User;
use App\Services\Report\DoctorService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;

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
            $this->authorize('viewSupplierDirectory', User::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);

            $doctors = $this->doctorService->getAllsuppliers($user, $perPage);

            return $this->paginatedResponse(
                SupplierResource::collection($doctors),
                $doctors
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب قائمة الأطباء. برجاء المحاولة لاحقاً.',
                422
            );
        }
    }
}
