<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone2' => $this->phone2,
            'address' => $this->address,
            // AUTHORITATIVE identity. Previously this read department->code.
            // The key and value shape are unchanged for existing consumers:
            // a doctor still reports "doctor", a supplier still "supplier".
            'role' => $this->role?->code,

            // Structured role object for consumers that want more than the code.
            'role_details' => $this->role ? [
                'id' => $this->role->id,
                'code' => $this->role->code,
                'name' => $this->role->name,
            ] : null,

            // LEGACY (deprecated): the organisational department, kept for
            // frontend compatibility. It is NOT an access-control value.
            'department_id' => $this->department_id,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null,
            'img' => $this->img
                ? asset('storage/'.$this->img)
                : null,
            'created_at' => $this->created_at->format('Y-m-d'),
            'schedule' => $this->schedules->map(function ($day) {
                return [
                    'name' => $day->day_name,
                    'active' => (bool) $day->active,
                    'from' => $day->from ? date('h:i A', strtotime($day->from)) : null,
                    'to' => $day->to ? date('h:i A', strtotime($day->to)) : null,
                ];
            }),
        ];
    }
}
