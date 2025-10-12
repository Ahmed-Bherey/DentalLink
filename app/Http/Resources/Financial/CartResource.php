<?php

namespace App\Http\Resources\Financial;

use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
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
            'id'            => $this->id,
            'product_id'    => $this->product?->id,
            'category_id'   => (int)$this->product?->category_id,
            'category_name' => $this->product?->category?->name,
            'name'          => $this->product?->name,
            'desc'          => $this->product?->desc,
            'img'           => $this->product?->img,
            'price'         => (int)$this->product?->price,
            'quantity'      => (int)$this->quantity,
        ];
    }
}
