<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\NotificationsCenterService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;

class NotificationsCenterController extends Controller
{
    use ApiResponse;

    protected $notificationsCenterService;

    public function __construct(NotificationsCenterService $notificationsCenterService)
    {
        $this->notificationsCenterService = $notificationsCenterService;
    }

    public function getUserNotification()
    {
        try {
            $user = request()->user();
            $notifications = $this->notificationsCenterService->getAllNotification($user);

            return $this->createSuccessResponse('تم جلب الإشعارات بنجاح', $notifications);
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
