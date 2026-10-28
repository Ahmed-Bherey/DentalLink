<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Http\Resources\Report\DoctorResource;
use App\Models\User;
use App\Services\Report\SupplierService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
            $this->authorize('viewDoctorDirectory', User::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);

            $doctors = $this->supplierService->getAllDoctors($user, $perPage);

            return $this->paginatedResponse(
                DoctorResource::collection($doctors),
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

    public function showDoctorDetails($doctorId)
    {
        try {
            $doctor = User::findOrFail($doctorId);

            $this->authorize('viewCounterparty', $doctor);

            $data = ['doctor' => $doctor];

            return $this->successResponse([
                'doctor' => new DoctorResource($data['doctor']),
            ]);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (ModelNotFoundException $e) {
            // Must match the policy's denyAsNotFound shape exactly. If a missing
            // id returned a different status from a forbidden one, the endpoint
            // would still be an enumeration oracle — just in reverse.
            return $this->errorResponse('غير موجود', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب تفاصيل الطبيب',
                422
            );
        }
    }
}
