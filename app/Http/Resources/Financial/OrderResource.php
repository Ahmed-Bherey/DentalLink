<?php

namespace App\Http\Resources\Financial;

use App\Support\Money;
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
        if ($authUser->isDoctor()) {
            $name = optional($this->orderItems->first()?->product?->user)->name;
        } elseif ($authUser->isSupplier()) {
            $name = $this->doctor->name;
        }

        $desc = null;
        if ($this->status === 'delete_pending' || $this->status === 'delivered') {
            $returnedProduct = $this->orderItems
                ->firstWhere('status', 'delete_pending');

            $doctorName = $this->doctor?->name ?? 'غير معروف';
            $orderId = $this->id;

            if ($returnedProduct) {
                $productName = $returnedProduct->product?->name ?? 'منتج غير محدد';

                // STAGE 7.5: this sentence describes a return AWAITING a
                // decision (the line was matched on status delete_pending), so
                // it must read the pending figure. Stage 7 split the overloaded
                // column in two and `returned_quantity` now means "cumulative
                // APPROVED" — which is 0 on a fresh request, so this message was
                // reporting "return of (0)".
                $returnedQty = (int) $returnedProduct->pending_return_quantity;

                $desc = "قام الطبيب: {$doctorName} بطلب إرجاع عدد ({$returnedQty}) من المنتج: {$productName}، من الطلب رقم ({$orderId}).";
            } else {
                $desc = "قام الطبيب: {$doctorName} بطلب إرجاع الطلب رقم ({$orderId}).";
            }
        }

        return [
            'id' => $this->id,
            'name' => $name,
            'img' => $this->doctor?->img ? asset('storage/'.$this->doctor->img) : null,
            'notes' => $this->notes,
            'status' => $this->status,
            'status_name' => $this->status_name,
            // The order's historical value: the agreed package price if this is
            // a package order, otherwise the sum of the lines' frozen totals.
            //
            // STAGE 7.5: cast to float. `orders.price` is DECIMAL, which PDO
            // returns as a STRING, so package orders emitted "500.00" while
            // ordinary orders emitted 30.0 — the same field with two JSON types
            // depending on the order kind.
            'total_price' => (float) Money::of($this->price ?? $this->total_order_price),
            'checked' => true,
            'desc' => $desc,
            'is_package' => $this->price != null,
            'is_order' => trim(strtolower($this->status)) === 'delete_pending',
            'created_at' => $this->created_at->format('Y-m-d'),

            // المنتجات الخاصة بالطلب
            //
            // STAGE 7.5: delegated to OrderItemResource. The inline array this
            // replaced built `price` and `total_price` from the supplier's LIVE
            // price and cast them to (int), so an old order reported today's
            // price, truncated — and its lines no longer summed to the order's
            // own historical total_price directly above.
            'products' => OrderItemResource::collection($this->orderItems),
        ];
    }
}
