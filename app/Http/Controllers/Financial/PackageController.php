<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Financial\Package;
use App\Http\Controllers\Controller;
use App\Services\Financial\PackageService;
use App\Http\Resources\Store\InventoryResource;
use App\Http\Resources\Financial\PackageResource;
use App\Http\Requests\Financial\BuyPackageRequest;
use App\Http\Requests\Financial\PackageStoreRequest;
use App\Http\Requests\Financial\PackageUpdateRequest;
use App\Http\Resources\Shopping\PackageProductResource;

class PackageController extends Controller
{
    use ApiResponse;
    protected $packageService;

    public function __construct(PackageService $packageService)
    {
        $this->packageService = $packageService;
    }

    public function index(Request $request)
    {
        try {
            $supplier = $request->user();
            $perPage = $request->get('per_page', 10);
            $search = $request->query('search');
            $from = $request->query('from_date');
            $to = $request->query('to_date');

            $packages = $this->packageService->getAllPackages($supplier, $perPage, $search, $from, $to);

            return $this->paginatedResponse(
                PackageResource::collection($packages),
                $packages
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب بيانات العروض',
                422
            );
        }
    }

    // انشاء عرض من المورد
    public function createPackage(PackageStoreRequest $request)
    {
        try {
            $supplier = request()->user();
            $package = $this->packageService->createPackage($supplier, $request->validated());

            return $this->createSuccessResponse(
                'تم إضافة الباقة بنجاح',
                new PackageResource($package),
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
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

    public function show($package_id)
    {
        try {

            $package = $this->packageService->show($package_id, request()->user());

            return $this->successResponse(new PackageResource($package));
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب بيانات الباقة',
                422
            );
        }
    }

    public function remainingProducts(Request $request, $packageId)
    {
        try {
            $supplier = $request->user();
            $perPage = $request->get('per_page', 10);
            $search = $request->query('search');

            $products = $this->packageService->getRemainingProducts($packageId, $supplier, $perPage, $search);

            return $this->paginatedResponse(
                InventoryResource::collection($products),
                $products
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب المنتجات المتبقية',
                422
            );
        }
    }


    public function update(PackageUpdateRequest $request, Package $package)
    {
        try {
            $updated = $this->packageService->update($package, $request->validated());

            if (!$updated || !$updated->exists) {
                return $this->errorResponse('فشل في تحديث الباقة', 422);
            }

            return $this->successResponse(new PackageResource($updated));
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
    }


    // حذف باقة
    public function destroy(Package $package)
    {
        try {
            //$this->authorize('delete', $package);

            $this->packageService->delete($package);

            return $this->successResponse('تم حذف العرض بنجاح');
        } catch (\Exception $e) {
            return $this->errorResponse('تعذر حذف العرض', 422);
        }
    }

    // تفعيل/تعطيل باقة
    public function toggleStatus(Package $package)
    {
        try {
            $updated = $this->packageService->toggleStatus($package);

            return $this->createSuccessResponse(
                $updated->active
                    ? 'تم تفعيل الباقة بنجاح'
                    : 'تم إلغاء تفعيل الباقة بنجاح',
                ['active' => $updated->active],
                200
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // عرض كل الباقات فى الشوبينج للطبيب
    public function allPackages(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $search = $request->query('search');

            $packages = $this->packageService->getAllShoppingPackages($perPage, $search);

            return $this->paginatedResponse(
                PackageResource::collection($packages),
                $packages
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب بيانات العروض',
                422
            );
        }
    }
}
