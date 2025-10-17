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
            $perPage = request()->get('per_page', 10);
            $notificationsCenter = $this->notificationsCenterService->getAllNotification($user, $perPage);
            return $this->paginatedResponse(
                NotificationsCenterResource::collection($notificationsCenter),
                $notificationsCenter,
            );
        } catch (Exception $e) {
            return $this->errorResponse(
                'عذراً، حدث خطأ أثناء جلب البيانات. برجاء المحاولة لاحقاً',
                422
            );
        }
    }
}
