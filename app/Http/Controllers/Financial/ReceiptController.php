<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\ReceiptStoreRequest;
use App\Http\Requests\Financial\ReceiptUpdateRequest;
use App\Http\Resources\Financial\ReceiptCollection;
use App\Http\Resources\Financial\ReceiptResource;
use App\Models\Financial\Receipt;
use App\Services\Financial\ReceiptService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

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
            $this->authorize('viewAny', Receipt::class);

            $receipts = $this->receiptService->index($request->user())->get();

            // نجروبهم حسب الشهر/السنة
            $grouped = $receipts->groupBy(function ($receipt) {
                return $receipt->date->format('Y-m');
            })->map(function ($receipts, $month) use ($request) {
                return [
                    'date' => $month,
                    'total_price' => (float) $receipts->sum('value'),
                    'receipts' => (new ReceiptCollection($receipts))->toArray($request),
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
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
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
            $this->authorize('create', Receipt::class);

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
                        'date' => $monthYear,
                        'total_price' => (float) $receipts->sum('value'),
                        'receipts' => ReceiptResource::collection(
                            $receipts->sortByDesc('created_at')->values()
                        ),
                    ];
                })
                ->values();

            // Pagination على مستوى الشهور (الجروبات)
            $perPage = 10; // عدد الشهور في الصفحة
            $current = LengthAwarePaginator::resolveCurrentPage();
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
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
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
            $receipt = Receipt::findOrFail($id);

            $this->authorize('view', $receipt);

            return $this->successResponse(new ReceiptResource($receipt));
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse('الإيصال غير موجود', 404);
        }
    }

    public function update(ReceiptUpdateRequest $request, $id)
    {
        try {
            $receipt = Receipt::findOrFail($id);

            $this->authorize('update', $receipt);

            $receipt = $this->receiptService->update($receipt, $request->validated());

            return $this->createSuccessResponse('تم تحديث الإيصال بنجاح', new ReceiptResource($receipt));
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse('تعذر تحديث الإيصال', 422);
        }
    }

    public function destroy($id)
    {
        try {
            $receipt = Receipt::findOrFail($id);

            $this->authorize('delete', $receipt);

            $this->receiptService->delete($receipt);

            return $this->successResponse('تم حذف الإيصال بنجاح');
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
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
            $this->authorize('deleteAny', Receipt::class);

            $deleted = $this->receiptService->deleteByDate($request->user(), $validated['date']);

            if (! $deleted) {
                return $this->errorResponse('لا يوجد إيصالات في هذا التاريخ', 404);
            }

            return $this->successResponse('تم حذف جميع الإيصالات بنجاح');
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse('تعذر حذف الإيصالات', 422);
        }
    }
}
