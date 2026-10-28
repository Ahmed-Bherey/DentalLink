<?php

namespace App\Http\Resources\Store;

use App\Models\Shopping\FavoriteProduct;
use App\Support\Money;
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
            'id' => $this->id,
            'product_id' => $this->id,
            'name' => $this->name,
            'supplier_name' => $this->user->name,
            'category_id' => $this->category_id,
            'category_name' => $this->category?->name,
            'city_id' => (int) $this->user->city_id,
            'city_name' => $this->user->city?->name,
            'img' => $this->img,
            'desc' => $this->desc,

            // STAGE 7: the canonical product this holding refers to, so a client
            // can group offers of the same item across suppliers.
            'product_master_id' => $this->product_master_id,

            // The SELLING price. Previously cast to (int), which reported a
            // price of 10.50 as 10 - the same truncation Stage 6 removed from
            // the payment endpoints.
            'price' => (float) Money::of($this->price),

            // The supplier's COST. Commercially sensitive: it reveals their
            // margin, so it is exposed ONLY to the owner of the offer and never
            // to a doctor browsing the catalogue.
            'base_price' => $this->when(
                $request->user() !== null && (int) $request->user()->id === (int) $this->user_id,
                fn () => $this->base_price === null ? null : (float) Money::of($this->base_price)
            ),

            'quantity' => (int) $this->quantity,
            'rating' => 5,
            'status' => $this->getStatus(),
            'favorite' => $this->favoriteProducts ? $this->whenLoaded('favoriteProducts', fn () => $this->favoriteProducts->isNotEmpty()) : false,
            'is_added' => $this->whenLoaded('carts', fn () => $this->carts->isNotEmpty()),
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
        if (! $doctor) {
            return false;
        }

        return FavoriteProduct::where('doctor_id', $doctor->id)
            ->where('product_id', $this->id)
            ->exists();
    }
}
