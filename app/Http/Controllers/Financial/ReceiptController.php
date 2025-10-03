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
use App\Models\Financial\Receipt;

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
            // نجيب query من الخدمة
            $query = $this->receiptService->index($request->user());

            // نعمل paginate على مستوى query
            $paginator = $query->paginate(10);

            // نجهز الكولكشن و نحوله Array علشان نتجنب data of data
            $collection = (new ReceiptCollection($paginator->getCollection()))
                ->toArray($request);

            // نرجع الاستجابة باستخدام دالة موحدة
            return $this->paginatedResponse($collection, $paginator);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء تحميل الفواتير',
                500
            );
        }
    }

    public function indexTest(Request $request)
    {
        try {
            // نجيب query من الخدمة
            $user = request()->user();
            $receipts = Receipt::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')->get();

            // نرجع الاستجابة باستخدام دالة موحدة
            return response()->json($receipts);
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
            $receipts = $this->receiptService->store($request->user(), $request->validated());

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
        try {
            $receipt = $this->receiptService->update($id, $request->user(), $request->validated());

            return $this->createSuccessResponse('تم تحديث الإيصال بنجاح', new ReceiptResource($receipt));
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
