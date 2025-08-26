<?php

namespace App\Http\Controllers\Auth;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Services\Auth\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Auth\UserResource;

class UserAuthController extends Controller
{
    use ApiResponse;
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    // تسجيل الدخول
    public function login(AuthRequest $request)
    {
        try {
            $user = $this->authService->login(
                $request->login,
                $request->password
            );

            if (!$user) {
                return $this->errorResponse('Invalid credentials', 401);
            }

            // ✅ تحقق من أن الحساب مفعل
            if ($user->active != 1) {
                throw new \Exception('حسابك لم يتم تفعيله بعد');
            }

            $token = $user->createToken('API Token')->plainTextToken;

            $user = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'code' => $user->department?->code,
                'token' => $token,
            ];

            return $this->successResponse($user, 200);
        } catch (Exception $e) {
            return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        }
    }

    // انشاء حساب
    public function userRegister(RegisterRequest $request)
    {
        //try {
            $user = $this->authService->register($request->validated());

            $token = $user->createToken('API Token')->plainTextToken;

            return $this->createSuccessResponse('تم انشاء حسابك بنجاح', new UserResource($user), 201);
        // } catch (Exception $e) {
        //     return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        // }
    }
}
