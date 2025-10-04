<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\OrderExpense;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $user = request()->user();
        $orderExpense = OrderExpense::where(['doctor_id' => $this->doctor_id, 'supplier_id' => $this->supplier_id])
            ->latest()->first();

        $authUser = $request->user();
        $name = null;
        if ($authUser->department?->code === 'doctor') {
            $name = $orderExpense->supplier?->name;
        } elseif ($authUser->department?->code === 'supplier') {
            $name = $orderExpense->doctor?->name;
        }
        return [
            'id'            => $this->id,
            'name'          => $name,
            'supplier_name' => $this->supplier->name,
            'paid'          => $this->amount,
            'remaining'     => $orderExpense - $this->amount,
            'created_at'    => $this->created_at->formant('Y-m-d'),
        ];
    }
}
