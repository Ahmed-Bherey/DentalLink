<?php

namespace App\Exports;

use App\Models\Order;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

class DeliveredOrdersExport implements FromCollection
{
    protected $orders;

    public function __construct($orders)
    {
        $this->orders = $orders;
    }

    public function collection(): Collection
    {
        return collect($this->orders->map(function ($order) {
            return [
                'id' => $order->id,
                'doctor_name' => $order->doctor?->name,
                'notes' => $order->notes,
                'status' => $order->status_name,
                'total_order_price' => $order->total_order_price,
                'created_at' => $order->created_at->format('Y-m-d H:i'),
                'products' => $order->orderItems->map(function ($item) {
                    return $item->product?->name . ' (x' . $item->quantity . ')';
                })->implode(', ')
            ];
        }));
    }

    public function headings(): array
    {
        return [
            '#',
            'Doctor Name',
            'Notes',
            'Status',
            'Total Order Price',
            'Created At',
            'Products',
        ];
    }
}
