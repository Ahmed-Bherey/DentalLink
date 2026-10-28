<?php

namespace App\Http\Resources\Financial;

use App\Models\Financial\OrderExpense;
use App\Services\Financial\LedgerService;
use App\Support\Money;
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
        if ($authUser->isDoctor()) {
            $name = $orderExpense?->supplier?->name;
        } elseif ($authUser->isSupplier()) {
            $name = $orderExpense?->doctor?->name;
        }

        // Stage 6: the `(int)` casts here truncated every fractional amount —
        // a payment of 150.75 was reported to both parties as 150. `remaining`
        // was also recomputed inline (note the stored column commented out
        // beside it, a workaround for a balance that used to be wrong). Both
        // now come from LedgerService, the single definition of the balance.
        return [
            'id' => $this->id,
            'name' => $name,
            'supplier_name' => $this->supplier?->name,
            'paid' => (float) Money::of($this->amount),
            'requested_paid' => $this->requested_amount === null
                ? null
                : (float) Money::of($this->requested_amount),
            'remaining' => (float) app(LedgerService::class)->outstanding($orderExpense),
            'date' => $this->date ?? $this->created_at->format('Y-m-d'),
        ];
    }
}
