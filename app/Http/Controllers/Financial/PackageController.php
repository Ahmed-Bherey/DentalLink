<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Financial\Package;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\BuyPackageRequest;
use App\Services\Financial\PackageService;
use App\Http\Resources\Financial\PackageResource;
use App\Http\Requests\Financial\PackageStoreRequest;

class PackageController extends Controller
{
    use ApiResponse;
    protected $packageService;

    public function __construct(PackageService $packageService)
    {
        $this->packageService = $packageService;
    }

    // انشاء عرض من المورد
    public function createPackage(PackageStoreRequest $request)
    {
        //try {
            $supplier = request()->user();
            $package = $this->packageService->createPackage($supplier, $request->validated());

            return $this->createSuccessResponse(
                'تم إضافة الباقة بنجاح',
                new PackageResource($package),
            );
        // } catch (Exception $e) {
        //     return $this->errorResponse(
        //         'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
        //         422
        //     );
        // }
    }

    // الطبيب يشترى العرض
    public function buyPackage(BuyPackageRequest $request, $packageId)
    {
        try {
            $doctor = $request->user(); // الطبيب الحالي
            $package = Package::with('packageItems')->findOrFail($packageId);

            $order = $this->packageService->buyPackage($doctor, $package, $request->validated());
            return $this->successResponseWithoutData(
                'تم طلب العرض بنجاح',
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
