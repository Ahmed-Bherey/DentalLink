<?php

namespace App\Http\Resources\Financial;

use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
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
            'id'          => $this->id,
            'name'        => $this->name,
            'desc'        => $this->desc,
            'total_price' => $this->price,
            'checked'     => (bool)$this->active,
            'created_at'  => $this->created_at?->format('Y-m-d'),
            'products'    => $this->packageItems->map(function ($item) {
                return [
                    'id'                => $item->id,
                    'product_id'        => $item->product?->id,
                    'category_id'       => (int)$item->product?->category_id,
                    'category_name'     => $item->product?->category?->name,
                    'name'              => $item->product?->name,
                    'desc'              => $item->product?->desc,
                    'img'               => $item->product?->img,
                    'price'             => (int)$item->product?->price,
                    'quantity'          => (int)$item->quantity,
                ];
            }),
        ];
    }
}
