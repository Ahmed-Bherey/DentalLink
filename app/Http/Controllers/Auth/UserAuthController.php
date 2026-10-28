<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\Auth\UserResource;
use App\Models\FcmToken;
use App\Services\Auth\AuthService;
use App\Traits\ApiResponse;
use Exception;
use Illuminate\Http\Request;

class UserAuthController extends Controller
{
    use ApiResponse;

    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * عرض بروفايل المستخدم الحالى
     */
    public function showProfile(Request $request)
    {
        $user = $request->user();

        $profile = $this->authService->getProfile($user);

        return $this->successResponse(new UserResource($profile), 200);
    }

    // تسجيل الدخول
    public function login(AuthRequest $request)
    {
        // try {
        $user = $this->authService->login(
            $request->login,
            $request->password
        );

        if (! $user) {
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
            'phone2' => $user->phone2,
            'address' => $user->address,
            'city_id' => $user->city_id,
            'city_name' => $user->city?->name,
            // AUTHORITATIVE identity (Stage 3). Same key, same values as before.
            'role' => $user->roleCode(),
            // LEGACY, retained for frontend compatibility.
            'department_id' => $user->department_id,
            'token' => $token,
        ];

        return $this->successResponse($user, 200);
        // } catch (Exception $e) {
        //     return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        // }
    }

    // انشاء حساب
    public function userRegister(RegisterRequest $request)
    {
        try {
            $user = $this->authService->register($request->validated());
            $token = $user->createToken('API Token')->plainTextToken;

            return $this->createSuccessResponse('تم انشاء حسابك بنجاح', new UserResource($user), 201);
        } catch (Exception $e) {
            return $this->errorResponse('عذرا حدث خطأ ما, برجاء المحاولة مرة اخرى', 422);
        }
    }

    // تحديث بيانات الحساب
    public function updateProfile(UpdateProfileRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('img')) {
            $data['img'] = $request->file('img');
        }

        $user = auth()->user();

        $updated = $this->authService->updateProfile($user, $data);

        return response()->json([
            'status' => true,
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'data' => $updated,
        ]);
    }

    public function updateToken(Request $request)
    {
        try {
            // ✅ التحقق من صحة البيانات
            $validated = $request->validate([
                'fcm_token' => 'required|string',
                'device' => 'required|string|in:web,android,ios',
            ]);

            // ✅ المستخدم الحالي
            $user = auth()->user();

            if (! $user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated.',
                ], 401);
            }

            // ✅ تحقق أولاً إذا كان هذا الـ token موجود مسبقًا للمستخدم
            $existingToken = FcmToken::where('user_id', $user->id)
                ->where('fcm_token', $validated['fcm_token'])
                ->first();

            if (! $existingToken) {
                // ➕ إنشاء سجل جديد للمستخدم
                FcmToken::create([
                    'user_id' => $user->id,
                    'fcm_token' => $validated['fcm_token'],
                    'device' => $validated['device'],
                ]);
            }

            // ✅ الاستجابة الناجحة
            return response()->json([
                'status' => true,
                'message' => 'FCM token saved successfully.',
            ], 200);
        } catch (\Exception $e) {
            // ⚠️ التعامل مع أي خطأ غير متوقع
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteToken(Request $request)
    {

        // ✅ المستخدم الحالي
        $user = request()->user();

        $fcmTokens = FcmToken::where('user_id', $user->id)->get();

        foreach ($fcmTokens as $fcmToken) {
            $fcmToken->delete();
        }
    }
}
