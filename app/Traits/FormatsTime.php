<?php

namespace App\Traits;

trait FormatsTime
{
    /**
     * Format time from 24-hour to 12-hour am/pm format
     */
    protected function formatTime(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        return date('h:i A', strtotime($time));
    }
}
