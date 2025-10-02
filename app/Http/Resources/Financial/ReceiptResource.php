<?php

namespace App\Http\Resources\Financial;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptResource extends JsonResource
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
            'id'    => $this->id,
            'name'  => $this->name,
            'price' => $this->value,
            'img'   => $this->img ? asset('storage/' . $this->img) : null,
            'date'  => $this->date,
        ];
    }
}
