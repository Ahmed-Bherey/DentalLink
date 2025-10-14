<?php

namespace App\Http\Resources\Financial;

use App\Models\Store\Inventory;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $authUser = $request->user();

        $name = null;
        if ($authUser->department?->code === 'doctor') {
            $name = optional($this->orderItems->first()?->product?->user)->name;
        } elseif ($authUser->department?->code === 'supplier') {
            $name = $this->doctor->name;
        }

        return [
            'id'                => $this->id,
            'name'       => $name,
            'notes'             => $this->notes,
            'status'            => $this->status,
            'status_name'       => $this->status_name,
            'total_order_price' => $this->total_order_price,
            'created_at'        => $this->created_at->format('Y-m-d'),

            // المنتجات الخاصة بالطلب
            'products'       => $this->orderItems->map(function ($item) {
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
                    'quantityAvailable' => $item->product?->quantity,
                    'total_price'       => $item->product?->price * $item->quantity,
                ];
            }),
        ];
    }
}
