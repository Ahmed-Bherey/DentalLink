<?php

namespace App\Http\Controllers\Financial;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Financial\Payment;
use App\Http\Controllers\Controller;
use App\Models\Financial\OrderExpense;
use App\Services\Financial\PaymentService;
use App\Http\Requests\Financial\PaymentRequest;
use App\Http\Resources\Financial\PaymentResource;
use App\Http\Requests\Financial\UpdatePaymentRequest;
use App\Http\Requests\Financial\UpdatePaymentStatusRequest;

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
            // $orderExpense = OrderExpense::where(['doctor_id' => 1, 'supplier_id' => 2])
            //     ->latest()->first();
            //     dd($orderExpense);
            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $payments = $this->paymentService->index($user, $perPage);
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
        try {
            $user = request()->user();
            $payment = $this->paymentService->store($user, $request->validated());
            return $this->createSuccessResponse(
                'تم انشاء المدفوعة بنجاح',
                new PaymentResource($payment),
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
                422
            );
        }
    }

    // تحديث مدفوعة
    public function update(UpdatePaymentRequest $request, $payment_id)
    {
        //try {
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
        // } catch (Exception $e) {
        //     return $this->errorResponse(
        //         'عذراً، حدث خطأ ما. برجاء المحاولة لاحقاً',
        //         422
        //     );
        // }
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
}
