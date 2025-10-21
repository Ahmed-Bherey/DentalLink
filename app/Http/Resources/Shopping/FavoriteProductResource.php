<?php

namespace App\Http\Resources\Shopping;

use App\Models\Financial\Cart;
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
        $doctor = $request->user();
        return [
            'id'            => $this->id,
            'doctor_id'            => $this->doctor_id,
            'product_id'            => $this->product_id,
            'name'          => $this->product?->name,
            'supplier_name' => $this->product?->user->name,
            'category_id'   => $this->product?->category_id,
            'category_name' => $this->product?->category?->name,
            'city_id'       => (int)$this->product?->user->city_id,
            'city_name'     => $this->product?->user->city?->name,
            'img'           => $this->product?->img,
            'desc'          => $this->product?->desc,
            'price'         => (int)$this->product?->price,
            'quantity'      => (int)$this->product?->quantity,
            'rating'        => 5,
            'favorite'      => true, // لأنه موجود فعلاً في المفضلة
            'is_added'      => $this->isAddedToCart($doctor),
        ];
    }

    private function isAddedToCart($doctor): bool
    {
        if (!$doctor || !$this->product?->id) {
            return false;
        }

        return Cart::where('doctor_id', $doctor->id)
            ->where('product_id', $this->product->id)
            ->exists();
    }
}
