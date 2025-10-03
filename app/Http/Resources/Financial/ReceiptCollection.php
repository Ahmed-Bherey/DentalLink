<?php

namespace App\Http\Resources\Financial;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
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
        // group by month-year
        $grouped = $this->collection->groupBy(function ($item) {
            return $item->date->format('Y-m');
        })->map(function ($receipts, $monthYear) {
            return [
                'date'        => $monthYear,
                'total_price' => (float) $receipts->sum('value'),
                'receipts'    => ReceiptResource::collection(
                    $receipts->sortByDesc('created_at')->values()
                ),
            ];
        })->values();

        return $grouped;
    }
}
