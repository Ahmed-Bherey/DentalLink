<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Financial\ReceiptService;
use App\Http\Resources\Financial\ReceiptResource;
use App\Http\Resources\Financial\ReceiptCollection;
use App\Http\Requests\Financial\ReceiptStoreRequest;
use App\Http\Requests\Financial\ReceiptUpdateRequest;

class ReceiptController extends Controller
{
    use ApiResponse;
    protected $receiptService;

    public function __construct(ReceiptService $receiptService)
    {
        $this->receiptService = $receiptService;
    }

    public function index(Request $request)
    {
        try {
            $receipts = $this->receiptService->index($request->user());

            return new ReceiptCollection($receipts);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء تحميل الفواتير',
                500
            );
        }
    }

    public function store(ReceiptStoreRequest $request)
    {
        try {
            $query = $this->receiptService->store($request->user(), $request->validated());

            $receipts = $query->paginate(10);

            return $this->paginatedResponse(
                new ReceiptCollection($receipts),
                $receipts
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    public function show($id)
    {
        try {
            $receipt = $this->receiptService->show($id, request()->user());

            return $this->successResponse(new ReceiptResource($receipt));
        } catch (\Exception $e) {
            return $this->errorResponse('الإيصال غير موجود', 404);
        }
    }

    public function update(ReceiptUpdateRequest $request, $id)
    {
        $validated = $request->validate([
            'name'  => 'sometimes|required|string|max:255',
            'price' => 'sometimes|required|numeric',
            'date'  => 'sometimes|required|date',
            'img'   => 'nullable|image|max:2048',
        ]);

        try {
            $receipt = $this->receiptService->update($id, $request->user(), $validated);

            return $this->successResponse(new ReceiptResource($receipt), 'تم تحديث الإيصال بنجاح');
        } catch (\Exception $e) {
            return $this->errorResponse('تعذر تحديث الإيصال', 422);
        }
    }

    public function destroy($id)
    {
        try {
            $this->receiptService->delete($id, request()->user());

            return $this->successResponse('تم حذف الإيصال بنجاح');
        } catch (\Exception $e) {
            return $this->errorResponse('تعذر حذف الإيصال', 422);
        }
    }

    public function destroyByDate(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required',
        ]);

        try {
            $deleted = $this->receiptService->deleteByDate($request->user(), $validated['date']);

            if (! $deleted) {
                return $this->errorResponse('لا يوجد إيصالات في هذا التاريخ', 404);
            }

            return $this->successResponse('تم حذف جميع الإيصالات بنجاح');
        } catch (\Exception $e) {
            return $this->errorResponse('تعذر حذف الإيصالات', 422);
        }
    }
}
