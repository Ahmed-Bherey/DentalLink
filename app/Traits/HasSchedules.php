<?php

namespace App\Traits;

trait HasSchedules
{
    use FormatsTime; // نستخدم الـ Trait السابق لتنسيق الوقت

    /**
     * Map the schedules to the desired output format
     *
     * @param \Illuminate\Support\Collection|null $schedules
     * @return \Illuminate\Support\Collection|null
     */
    protected function mapSchedules($schedules)
    {
        if (!$schedules) {
            return null;
        }

        return $schedules->where('active', 1)
            ->map(function ($schedule) {
                return [
                    'id'       => $schedule->id,
                    'day_name' => $schedule->day_name,
                    'from'     => $this->formatTime($schedule->from),
                    'to'       => $this->formatTime($schedule->to),
                ];
            });
    }
}
