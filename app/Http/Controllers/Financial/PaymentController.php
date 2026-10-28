<?php

namespace App\Http\Controllers\Financial;

use App\Exceptions\Financial\PaymentException;
use App\Exports\PaymentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\PaymentRequest;
use App\Http\Requests\Financial\UpdatePaymentRequest;
use App\Http\Requests\Financial\UpdatePaymentStatusRequest;
use App\Http\Resources\Financial\PaymentResource;
use App\Models\FcmToken;
use App\Models\Financial\Payment;
use App\Services\Financial\PaymentService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Maatwebsite\Excel\Facades\Excel;

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
            $this->authorize('viewAny', Payment::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $payments = $this->paymentService->search($user, $perPage);

            return $this->paginatedResponse(
                PaymentResource::collection($payments),
                $payments,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
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
        $this->authorize('create', Payment::class);

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

            if (! $paymentRecord) {
                return $this->errorResponse(
                    'عفوا, المدفوعة غير موجودة , برجاء اختيار مدفوعة صحيحة',
                    404
                );
            }
            $this->authorize('update', $paymentRecord);

            $payment = $this->paymentService->update($user, $request->validated(), $paymentRecord);

            return $this->successResponseWithId(
                'تم انشاء المدفوعة بنجاح',
                $payment->id,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (\Exception $e) {
            // NOTE: this handler still echoes the exception message to the client.
            // Narrowed to business-rule messages only now that authorization failures
            // are caught above; full error-response hardening is Stage 9.
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    // تحديث حالة المدفوعة من جانب الطبيب
    public function updatePaymentStatus(UpdatePaymentStatusRequest $request, $payment_id)
    {
        try {
            $paymentRecord = Payment::findOrFail($payment_id);

            if (! $paymentRecord) {
                return $this->errorResponse(
                    'عفوا, المدفوعة غير موجودة , برجاء اختيار مدفوعة صحيحة',
                    404
                );
            }
            $this->authorize('decide', $paymentRecord);

            $payment = $this->paymentService->updatePaymentStatus(
                $request->validated(),
                $paymentRecord,
                $request->user()
            );

            return $this->successResponseWithId(
                'تم تحديث حالة المدفوعة بنجاح',
                $payment->id,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
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
            $this->authorize('viewPending', Payment::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);
            $payments = $this->paymentService->pendingPyments($user, $perPage);

            return $this->paginatedResponse(
                PaymentResource::collection($payments),
                $payments,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
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

            if (! $paymentRecord) {
                return $this->errorResponse(
                    'عفوا, المدفوعة غير موجودة , برجاء اختيار مدفوعة صحيحة',
                    404
                );
            }

            $this->authorize('requestDeletion', $paymentRecord);

            // نرسل الطلب إلى الطبيب لتأكيد الحذف
            $payment = $this->paymentService->requestDelete($user, $paymentRecord);

            return $this->successResponseWithId(
                'تم إرسال طلب حذف المدفوعة للطبيب للمراجعة',
                $payment->id,
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
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
            $this->authorize('viewAny', Payment::class);

            $user = request()->user();
            $fileName = 'payments_'.now()->format('Y_m_d_H_i').'.xlsx';

            return Excel::download(new PaymentsExport($user), $fileName);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (PaymentException $e) {
            // Stage 6: financial refusals carry a specific, actionable reason
            // ("amount exceeds the outstanding balance"). The generic handler
            // below would replace it with "something went wrong".
            throw $e;
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
            $this->authorize('viewAny', Payment::class);

            $user = request()->user();
            $perPage = request()->get('per_page', 10);

            $payments = $this->paymentService->search($user, $perPage);

            return $this->paginatedResponse(
                PaymentResource::collection($payments),
                $payments
            );
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
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

            // foreach($fcmTokens as $fcmToken){
            //     $fcmToken->delete();
            // }
            return response()->json($fcmTokens);
        } catch (AuthorizationException $e) {
            // Stage 4: authorization failures render centrally. Never swallow them.
            throw $e;
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
