<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Password change and password reset.
 *
 * Reset uses Laravel's native password broker (config/auth.php `passwords`),
 * which already provides securely generated, hashed, expiring, single-use
 * tokens. No custom token scheme was invented.
 */
class PasswordController extends Controller
{
    use ApiResponse;

    /**
     * Authenticated password change.
     *
     * Verifies the current password, applies the password policy, then revokes
     * every token so any credential captured before the change stops working.
     * The caller must sign in again — that is the point.
     */
    public function change(ChangePasswordRequest $request)
    {
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            // 422 with a field error, not 401: the session is valid, the input is not.
            return response()->json([
                'message' => 'Validation errors',
                'errors' => ['current_password' => ['كلمة المرور الحالية غير صحيحة.']],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($request->input('password')),
            'remember_token' => Str::random(60),
        ])->save();

        // Revoke all tokens, including the one that made this request.
        $user->tokens()->delete();

        return $this->successResponseWithoutData(
            'تم تغيير كلمة المرور بنجاح. برجاء تسجيل الدخول مرة أخرى.',
            200
        );
    }

    /**
     * Request a reset link.
     *
     * Always answers identically whether or not the address exists, so this
     * endpoint cannot be used to enumerate accounts.
     */
    public function sendResetLink(SendPasswordResetLinkRequest $request)
    {
        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            return $this->errorResponse('برجاء الانتظار قبل طلب رابط جديد.', 429);
        }

        // RESET_LINK_SENT and INVALID_USER both return the same response.
        return $this->successResponseWithoutData(
            'إذا كان البريد الإلكتروني مسجلاً لدينا، فسيصلك رابط إعادة تعيين كلمة المرور.',
            200
        );
    }

    /**
     * Complete a reset with a token.
     *
     * The broker validates the token, enforces expiry, and deletes the token on
     * success so it cannot be reused.
     */
    public function reset(ResetPasswordRequest $request)
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // A reset means the account may have been compromised; drop
                // every existing API token.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->successResponseWithoutData('تم إعادة تعيين كلمة المرور بنجاح.', 200);
        }

        if ($status === Password::RESET_THROTTLED) {
            return $this->errorResponse('برجاء الانتظار قبل المحاولة مرة أخرى.', 429);
        }

        // INVALID_TOKEN and INVALID_USER are reported the same way so a caller
        // cannot distinguish "no such account" from "bad token".
        return response()->json([
            'message' => 'Validation errors',
            'errors' => ['token' => ['رابط إعادة التعيين غير صالح أو منتهي الصلاحية.']],
        ], 422);
    }

    /**
     * Exposes the password requirements so the frontend can display them
     * without hardcoding a duplicate copy of the policy.
     */
    public function policy(Request $request)
    {
        return $this->successResponse([
            'min_length' => \App\Support\PasswordPolicy::minLength(),
            'description' => \App\Support\PasswordPolicy::description(),
        ], 200);
    }
}
