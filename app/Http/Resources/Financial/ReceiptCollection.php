<?php

namespace App\Http\Resources\Financial;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ReceiptCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        // Group receipts by month + year
        $grouped = $this->collection->groupBy(function ($item) {
            return $item->date->format('Y-m'); // مثال: 2025-10
        });

        // اعادة صياغة الاستجابة بحيث يكون الشهر والسنة = key وتحته الفواتير
        return $grouped->map(function ($receipts, $monthYear) {
            return [
                'month_year'  => $monthYear, // 2025-10
                'total_price' => (float) $receipts->sum('value'),
                'receipts'    => ReceiptResource::collection($receipts),
            ];
        })->values(); // علشان يرجع Array مش associative
    }
}
