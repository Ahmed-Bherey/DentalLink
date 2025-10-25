<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Exports\PaymentsExport;
use App\Models\Financial\Payment;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Financial\OrderExpense;
use App\Services\Financial\PaymentService;
use App\Http\Requests\Financial\PaymentRequest;
use App\Http\Resources\Financial\PaymentResource;
use App\Http\Requests\Financial\UpdatePaymentRequest;
use App\Http\Requests\Financial\UpdatePaymentStatusRequest;
use App\Models\FcmToken;

class PaymentController extends Controller
{
    use ApiResponse;
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    // عرض قائمة المدفوعات للمورد والطبيب
    public function index()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $payments = $this->paymentService->search($user, $perPage);
            return $this->paginatedResponse(
                PaymentResource::collection($payments),
                $payments,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // انشاء مدفوعة
    public function store(PaymentRequest $request)
    {
        //try {
        $user = request()->user();
        $payment = $this->paymentService->store($user, $request->validated());
        return $this->createSuccessResponse(
            'تم انشاء المدفوعة بنجاح',
            new PaymentResource($payment),
        );
        // } catch (Exception $e) {
        //     return $this->errorResponse(
        //         'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
        //         422
        //     );
        // }
    }

    // تحديث مدفوعة
    public function update(UpdatePaymentRequest $request, $payment_id)
    {
        try {
            $user = request()->user();
            $paymentRecord = Payment::findOrFail($payment_id);

            if (!$paymentRecord) {
                return $this->errorResponse(
                    'عفوا, المدفوعة غير موجودة , برجاء اختيار مدفوعة صحيحة',
                    404
                );
            }
            $payment = $this->paymentService->update($user, $request->validated(), $paymentRecord);
            return $this->successResponseWithId(
                'تم انشاء المدفوعة بنجاح',
                $payment->id,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // تحديث حالة المدفوعة من جانب الطبيب
    public function updatePaymentStatus(UpdatePaymentStatusRequest $request, $payment_id)
    {
        try {
            $paymentRecord = Payment::findOrFail($payment_id);

            if (!$paymentRecord) {
                return $this->errorResponse(
                    'عفوا, المدفوعة غير موجودة , برجاء اختيار مدفوعة صحيحة',
                    404
                );
            }
            $payment = $this->paymentService->updatePaymentStatus($request->validated(), $paymentRecord);
            return $this->successResponseWithId(
                'تم تحديث حالة المدفوعة بنجاح',
                $payment->id,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // عرض المدفوعات المعلقة للطبيب
    public function pendingPyments()
    {
        try {
            $user = request()->user();
            if ($user->department->code != 'doctor') {
                return $this->errorResponse(
                    'عفوا, ليس لديك صلاحية',
                    403
                );
            }
            $perPage = request()->get('per_page', 10);
            $payments = $this->paymentService->pendingPyments($user, $perPage);
            return $this->paginatedResponse(
                PaymentResource::collection($payments),
                $payments,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // حذف مدفوعة
    public function deleteRequest($payment_id)
    {
        try {
            $user = request()->user();
            $paymentRecord = Payment::findOrFail($payment_id);

            if (!$paymentRecord) {
                return $this->errorResponse(
                    'عفوا, المدفوعة غير موجودة , برجاء اختيار مدفوعة صحيحة',
                    404
                );
            }

            // نرسل الطلب إلى الطبيب لتأكيد الحذف
            $payment = $this->paymentService->requestDelete($user, $paymentRecord);

            return $this->successResponseWithId(
                'تم إرسال طلب حذف المدفوعة للطبيب للمراجعة',
                $payment->id,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // تحميل المدفوعات فى صيغة اكسيل
    public function exportToExcel()
    {
        try {
            $user = request()->user();
            $fileName = 'payments_' . now()->format('Y_m_d_H_i') . '.xlsx';
            return Excel::download(new PaymentsExport($user), $fileName);
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء تصدير الملف. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // البحث والفلاتر
    public function search()
    {
        try {
            $user = request()->user();
            $perPage = request()->get('per_page', 10);

            // فقط نستدعي الخدمة
            $payments = $this->paymentService->search($user, $perPage);

            return $this->paginatedResponse(
                PaymentResource::collection($payments),
                $payments
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    public function getFcmToken()
    {
        try {
            $user = request()->user();
            $fcmTokens = FcmToken::where('user_id', $user->id)->get();
            return response()->json($fcmTokens);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
