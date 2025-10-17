<?php

namespace App\Services\System;

use App\Models\General\NotificationsCenter;
use App\Http\Resources\System\NotificationsCenterResource;

class NotificationsCenterService
{
    public function getAllNotification($user)
    {
        $notifications = NotificationsCenter::where('user_id', $user->id)
            ->latest()
            ->get();

        $today = [];
        $yesterday = [];
        $older = [];

        foreach ($notifications as $notification) {
            $date = $notification->created_at->toDateString();
            if ($date == now()->toDateString()) {
                $today[] = new NotificationsCenterResource($notification);
            } elseif ($date == now()->subDay()->toDateString()) {
                $yesterday[] = new NotificationsCenterResource($notification);
            } else {
                $older[] = new NotificationsCenterResource($notification);
            }
        }

        return [
            'today' => $today,
            'yesterday' => $yesterday,
            'older' => $older,
        ];
    }
}
