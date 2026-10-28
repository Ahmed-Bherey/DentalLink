<?php

namespace App\Http\Resources\Report;

use App\Models\Financial\OrderExpense;
use App\Services\Financial\LedgerService;
use App\Support\Money;
use App\Traits\HasSchedules;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorResource extends JsonResource
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

        // Stage 6: initialised because a Super Admin matches neither branch
        // below, which previously left this variable undefined.
        $orderExpense = null;

        if ($user->isSupplier()) {
            $orderExpense = OrderExpense::where(['doctor_id' => $this->id, 'supplier_id' => $user->id])
                ->latest()->first();
        } elseif ($user->isDoctor()) {
            $orderExpense = OrderExpense::where(['supplier_id' => $this->id, 'doctor_id' => $user->id])
                ->latest()->first();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'city_id' => $this->city_id,
            'city_name' => $this->city?->name,
            'phone' => $this->phone,
            'phone2' => $this->phone2,
            'address' => $this->address,
            // Stage 6: `(int)` truncated every fractional balance, and the
            // trailing `?? 0` was dead code — `??` binds looser than `-`, so it
            // could never apply to the subtraction it appeared to guard.
            'paid' => (float) Money::of($orderExpense?->paid),
            'total' => (float) Money::of($orderExpense?->total),
            'remaining' => (float) app(LedgerService::class)->outstanding($orderExpense),
            'schedules' => $this->mapSchedules($this->schedules),
        ];
    }
}
