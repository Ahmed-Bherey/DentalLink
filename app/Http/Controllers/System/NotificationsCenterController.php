<?php

namespace App\Http\Controllers\System;

use Exception;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\System\NotificationsCenterResource;
use App\Services\System\NotificationsCenterService;

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
        } catch (\Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
