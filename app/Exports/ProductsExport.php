<?php

namespace App\Exports;

use App\Models\Store\Product;
use Maatwebsite\Excel\Concerns\FromCollection;

class ProductsExport implements FromCollection
{
    protected $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function collection()
    {
        return Product::with('category')
            ->where('user_id', $this->user->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function map($product): array
    {
        return [
            $product->id,
            $product->name,
            $product->category?->name ?? 'بدون تصنيف',
            $product->price,
            $product->quantity,
            $product->desc,
            $product->created_at->format('Y-m-d'),
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'الاسم',
            'التصنيف',
            'السعر',
            'الكمية',
            'الوصف',
            'تاريخ الإضافة',
        ];
    }
}
