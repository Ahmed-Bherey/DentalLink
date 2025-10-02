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
        // Group receipts by date
        $grouped = $this->collection->groupBy(function ($item) {
            return $item->date->format('Y-m-d'); // صيغة التاريخ
        });

        // اعادة صياغة الاستجابة بحيث يكون التاريخ = key وتحته الفواتير
        return $grouped->map(function ($receipts, $date) {
            return [
                'date'        => $date,
                'total_price' => $receipts->sum('value'),
                'receipts' => ReceiptResource::collection($receipts),
            ];
        })->values(); // values علشان يرجّع Array مش associative
    }
}
