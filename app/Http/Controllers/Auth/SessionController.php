<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Session lifecycle: token revocation.
 *
 * Uses Sanctum's native personal-access-token mechanism. No new authentication
 * framework was introduced.
 */
class SessionController extends Controller
{
    use ApiResponse;

    /**
     * Revoke ONLY the token used for this request.
     *
     * Other devices stay signed in, which matches how a per-device token model
     * is expected to behave.
     */
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        // Guard for the transient token used by actingAs() in tests, which is
        // not a persisted row and therefore has nothing to delete.
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        return $this->successResponseWithoutData('تم تسجيل الخروج بنجاح', 200);
    }

    /**
     * Revoke every token belonging to the current user.
     *
     * Used for "sign out everywhere" and after a password change.
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return $this->successResponseWithoutData('تم تسجيل الخروج من جميع الأجهزة بنجاح', 200);
    }
}
