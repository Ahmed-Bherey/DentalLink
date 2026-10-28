<?php

namespace App\Exceptions;

use App\Exceptions\Financial\FulfilmentException;
use App\Exceptions\Financial\PaymentException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        /*
         * Render authorization failures in the application's standard error
         * envelope instead of Laravel's bare {"message": ...}.
         *
         * A policy that returns Response::denyAsNotFound() surfaces as 404 here,
         * which is how resources whose ids must not be enumerable (products,
         * carts, receipts, the user directory) hide their existence from a
         * non-owner. Everything else is a plain 403.
         */
        $this->renderable(function (AuthorizationException $e, $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $status = $e->status() ?? 403;

            return response()->json([
                'status' => false,
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'عفوا، ليس لديك صلاحية لتنفيذ هذا الإجراء.',
            ], $status);
        });

        /*
         * Stage 6: a payment refused on FINANCIAL grounds is a 422, not a 403.
         *
         * The distinction matters. An AuthorizationException means "you may not
         * touch this record". A PaymentException means the caller was permitted
         * and the request was well-formed, but the numbers do not allow it —
         * the amount exceeds the outstanding balance, or the payment is not in
         * a state that can be amended. That is unprocessable content.
         *
         * Registering it here also keeps these messages out of the generic
         * `catch (Exception)` blocks in the controllers, which would flatten a
         * precise financial reason into a generic failure message.
         */
        $this->renderable(function (PaymentException $e, $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        });

        /*
         * An order refused on STATE grounds is also a 422: the caller was
         * permitted and the request was well-formed, but the order's state does
         * not allow it — stock is short, the line was already fulfilled, the
         * goods were never delivered, nothing is left to return.
         */
        $this->renderable(function (FulfilmentException $e, $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        });
    }

    // sanctum تعديل رسالة
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return response()->json([
            'message' => 'يجب تسجيل الدخول أولاً.',
        ], 401);
    }
}
