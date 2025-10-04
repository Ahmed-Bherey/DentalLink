<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Financial\Receipt;
use App\Http\Controllers\Controller;
use App\Services\Financial\ReceiptService;
use Illuminate\Pagination\LengthAwarePaginator;
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
            // نجيب كل الفواتير ونرتبها
            $receipts = $this->receiptService->index($request->user())->get();

            // نجروبهم حسب الشهر/السنة
            $grouped = $receipts->groupBy(function ($receipt) {
                return $receipt->date->format('Y-m');
            })->map(function ($receipts, $month) use ($request) {
                return [
                    'date' => $month,
                    'total_price' => (float) $receipts->sum('value'),
                    'receipts' => (new ReceiptCollection($receipts))->toArray($request)
                ];
            })->values();

            // نعمل pagination على مستوى الجروبات (الشهور)
            $perPage = 10;
            $currentPage = LengthAwarePaginator::resolveCurrentPage();
            $currentItems = $grouped->slice(($currentPage - 1) * $perPage, $perPage)->values();

            $paginator = new LengthAwarePaginator(
                $currentItems,
                $grouped->count(),
                $perPage,
                $currentPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            return $this->paginatedResponse($paginator->items(), $paginator);
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
            // خد Query من الخدمة
            $query = $this->receiptService->store($request->user(), $request->validated());

            // هات كل الإيصالات (عدد كبير عشان نضمن grouping صحيح)
            $paginator = $query->paginate(1000);

            // Group by month-year بعد ترتيب التاريخ تنازلي
            $grouped = $paginator->getCollection()
                ->sortByDesc('date') // <-- ده يضمن ظهور يناير قبل ديسمبر حسب السنة والشهر
                ->groupBy(function ($item) {
                    return $item->date->format('Y-m');
                })
                ->map(function ($receipts, $monthYear) {
                    return [
                        'date'        => $monthYear,
                        'total_price' => (float) $receipts->sum('value'),
                        'receipts'    => ReceiptResource::collection(
                            $receipts->sortByDesc('created_at')->values()
                        ),
                    ];
                })
                ->values();

            // Pagination على مستوى الشهور (الجروبات)
            $perPage   = 10; // عدد الشهور في الصفحة
            $current   = LengthAwarePaginator::resolveCurrentPage();
            $currentItems = $grouped->forPage($current, $perPage);

            $paginatedGroups = new LengthAwarePaginator(
                $currentItems,
                $grouped->count(),
                $perPage,
                $current,
                ['path' => request()->url(), 'query' => request()->query()]
            );

            return $this->paginatedResponse(
                $paginatedGroups->values(),
                $paginatedGroups
            );
        } catch (\Exception $e) {
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
