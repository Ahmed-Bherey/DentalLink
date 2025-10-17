<?php

namespace App\Http\Resources\System;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationsCenterResource extends JsonResource
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
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'color' => $this->color,
            'created_at' => $this->created_at->format('Y-m-d'),
        ];
    }
}
