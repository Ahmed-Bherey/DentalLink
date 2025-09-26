<?php

namespace App\Http\Resources\Store;

use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
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
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'image' => $this->img,
            'description' => $this->desc,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'rating' => 5,
            'status' => $this->getStatus(),
        ];
    }

    private function getStatus(): string
    {
        if ($this->quantity == 0) {
            return 'OUTOFSTOCK';
        }

        if ($this->quantity <= 10) {
            return 'LOWSTOCK';
        }

        return 'INSTOCK';
    }
}
