<?php

namespace App\Services\Notifaction;

use App\Models\FcmToken;
use App\Jobs\SendFcmNotification;

class NotificationService
{
    public function notifyUser($userId, $title, $body, $data = [])
    {
        $tokens = FcmToken::where('user_id', $userId)->pluck('fcm_token');

        foreach ($tokens as $token) {
            // نستخدم job ليتم الإرسال في الخلفية
            SendFcmNotification::dispatch($token, $title, $body, $data);
        }
    }
}