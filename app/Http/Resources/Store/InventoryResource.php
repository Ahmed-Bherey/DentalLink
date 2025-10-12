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
            'id'            => $this->id,
            'name'          => $this->name,
            'supplier_name' => $this->user->name,
            'category_id'   => $this->category_id,
            'category_name' => $this->category?->name,
            'city_id'       => (int)$this->user->city_id,
            'city_name'     => $this->user->city?->name,
            'img'           => $this->img,
            'desc'          => $this->desc,
            'price'         => (int)$this->price,
            'quantity'      => (int)$this->quantity,
            'rating'        => 5,
            'status'        => $this->getStatus(),
            'is_added'      => $this->isFavoritedBy($request->user()),
        ];
    }

    private function getStatus(): string
    {
        if ($this->quantity == 0) {
            return 'OUTOFSTOCK';
        }

        if ($this->quantity <= 5) {
            return 'LOWSTOCK';
        }

        return 'INSTOCK';
    }
}
