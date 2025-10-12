<?php

namespace App\Http\Resources\Shopping;

use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteProductResource extends JsonResource
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
            'name'          => $this->product->name,
            'supplier_name' => $this->product->user->name,
            'category_id'   => $this->product->category_id,
            'category_name' => $this->product->category?->name,
            'city_id'       => (int)$this->product->user->city_id,
            'city_name'     => $this->product->user->city?->name,
            'img'           => $this->products->img,
            'desc'          => $this->product->desc,
            'price'         => (int)$this->product->price,
            'quantity'      => (int)$this->product->quantity,
            'rating'        => 5,
            'is_added'      => $this->isFavoritedBy($request->user()),
        ];
    }
}
