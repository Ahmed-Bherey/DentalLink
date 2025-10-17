<?php

namespace App\Services\System;

use App\Models\General\NotificationsCenter;

class NotificationsCenterService
{
    public function getAllNotification($user, $perPage = 10)
    {
        $notificationsCenter = NotificationsCenter::where('user_id', $user->id)
            ->paginate($perPage);
        return $notificationsCenter;
    }
}
