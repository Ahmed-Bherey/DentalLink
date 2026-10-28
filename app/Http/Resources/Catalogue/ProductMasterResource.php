<?php

namespace App\Http\Resources\Catalogue;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A canonical product as seen by the product picker and by Super Admin.
 *
 * Carries no price: a canonical product has none. `offers_count` lets a client
 * show how many suppliers stock it without loading them all.
 */
class ProductMasterResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'desc' => $this->desc,
            'img' => $this->img,
            'unit' => $this->unit,
            'is_active' => (bool) $this->is_active,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'offers_count' => $this->when(isset($this->products_count), fn () => (int) $this->products_count),
        ];
    }
}
