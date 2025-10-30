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

        $desc = null;
        if ($this->status === 'delete_pending') {
            $returnedProduct = $this->orderItems
                ->firstWhere('status', 'delete_pending');

            $doctorName = $this->doctor?->name ?? 'غير معروف';
            $orderId = $this->id;

            if ($returnedProduct) {
                $productName = $returnedProduct->product?->name ?? 'منتج غير محدد';
                $desc = "قام الطبيب \"{$doctorName}\" بطلب إرجاع المنتج \"{$productName}\" من الطلب رقم ({$orderId}).";
            } else {
                $desc = "قام الطبيب \"{$doctorName}\" بطلب إرجاع الطلب رقم ({$orderId}).";
            }
        }

        return [
            'id'                => $this->id,
            'name'              => $name,
            'img'               => $this->doctor?->img ? asset('storage/' . $this->doctor->img) : null,
            'notes'             => $this->notes,
            'status'            => $this->status,
            'status_name'       => $this->status_name,
            'total_price'       => $this->price ?? $this->total_order_price,
            'checked'           => true,
            'desc'              => $desc,
            'is_package'        => $this->price != null,
            'is_order'          => trim(strtolower($this->status)) === 'delete_pending',
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
                    'returned_quantity' => (int)$item->returned_quantity,
                    'quantityAvailable' => $item->product?->quantity,
                    'total_price'       => $item->product?->price * $item->quantity,
                ];
            }),
        ];
    }
}
