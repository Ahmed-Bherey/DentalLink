<?php

namespace App\Http\Resources\Financial;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
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
            'doctor_name' => $this->doctor->name,
            'supplier_name' => $this->supplier->name,
            'amount' => $this->amount,
            'created_at' => $this->created_at->formant('Y-m-d'),
        ];
    }
}
