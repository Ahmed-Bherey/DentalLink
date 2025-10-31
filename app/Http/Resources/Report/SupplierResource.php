<?php

namespace App\Http\Resources\Report;

use App\Traits\HasSchedules;
use App\Models\Financial\OrderExpense;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    use HasSchedules;
    public function toArray($request)
    {
        $user = request()->user();
        if ($user->department->code == 'supplier') {
            $orderExpense = OrderExpense::where(['doctor_id' => $this->id, 'supplier_id' => $user->id])
                ->latest()->first();
        } elseif ($user->department->code == 'doctor') {
            $orderExpense = OrderExpense::where(['supplier_id' => $this->id, 'doctor_id' => $user->id])
                ->latest()->first();
        }
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'city_id'   => $this->city_id,
            'city_name' => $this->city?->name,
            'phone'     => $this->phone,
            'phone2'    => $this->phone2,
            'address'   => $this->address,
            'paid'      => (int)$orderExpense?->paid ?? 0,
            'total'     => (int)$orderExpense?->total ?? 0,
            'remaining' => (int)$orderExpense?->total - $orderExpense?->paid /*(int)$orderExpense?->remaining*/ ?? 0,
            'schedules' => $this->mapSchedules($this->schedules),
        ];
    }
}
