<?php

namespace App\Http\Resources\Store;

use App\Models\Shopping\FavoriteProduct;
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
        $doctor = $request->user();
        return [
            'id'            => $this->id,
            'product_id'    => $this->id,
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
            'favorite' => $this->whenLoaded('favoriteProducts', fn() => $this->favoriteProducts->isNotEmpty()) ?? false,
            'is_added' => $this->whenLoaded('carts', fn() => $this->carts->isNotEmpty()),
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

    private function isAddedToFavorites($doctor): bool
    {
        if (!$doctor) {
            return false;
        }

        return FavoriteProduct::where('doctor_id', $doctor->id)
            ->where('product_id', $this->id)
            ->exists();
    }
}
