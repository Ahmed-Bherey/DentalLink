<?php

namespace App\Http\Controllers\Auth;

use Exception;
use App\Models\FcmToken;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Services\Auth\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AuthRequest;
use App\Http\Resources\Auth\UserResource;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;

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
        //try {
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
            'phone2' => $user->phone2,
            'address' => $user->address,
            'city_id' => $user->city_id,
            'city_name' => $user->city?->name,
            'role' => $user->department?->code,
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
            'data' => $updated
        ]);
    }

    public function updateToken(Request $request)
{
    $validator = Validator::make($request->all(), [
        'fcm_token' => 'required|string',
        'device' => 'required|in:mob,web,desktop',
        'device_id' => 'nullable|string',
    ]);

    if ($validator->fails()) {
        return response()->json($validator->errors(), 422);
    }

    $user = $request->user();

    if (!$user) {
        return response()->json(['error' => 'Unauthenticated'], 401);
    }

    // تحقق إن التوكن غير موجود مسبقًا لنفس المستخدم
    $exists = FcmToken::where('user_id', $user->id)
        ->where('fcm_token', $request->fcm_token)
        ->exists();

    if (!$exists) {
        FcmToken::create([
            'user_id' => $user->id,
            'device' => $request->device,
            'device_id' => $request->device_id,
            'fcm_token' => $request->fcm_token,
        ]);
    }

    return response()->json(['success' => true]);
}

}
