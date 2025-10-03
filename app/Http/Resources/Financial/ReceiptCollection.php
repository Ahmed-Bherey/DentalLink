<?php

namespace App\Http\Resources\Financial;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
            return $item->date->format('Y-m'); // مثال: 2025-09
        })->map(function ($receipts, $monthYear) {
            return [
                'date'        => $monthYear,
                'total_price' => (float) $receipts->sum('value'),
                'receipts'    => ReceiptResource::collection(
                    $receipts->sortByDesc('created_at')->values()
                ),
            ];
        })->values();

        // pagination على مستوى المجموعات (الشهور)
        $page    = request()->get('page', 1);
        $perPage = 10;

        $paged = new LengthAwarePaginator(
            $grouped->forPage($page, $perPage),
            $grouped->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [
            'status' => true,
            'data'   => $paged->items(),
            'meta'   => [
                'current_page' => $paged->currentPage(),
                'last_page'    => $paged->lastPage(),
                'per_page'     => $paged->perPage(),
                'total'        => $paged->total(),
            ],
            'links'  => [
                'first' => $paged->url(1),
                'last'  => $paged->url($paged->lastPage()),
                'prev'  => $paged->previousPageUrl(),
                'next'  => $paged->nextPageUrl(),
            ]
        ];
    }
}
