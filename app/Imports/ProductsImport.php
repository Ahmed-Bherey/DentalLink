<?php

namespace App\Imports;

use App\Models\Store\Product;
use App\Models\General\Category;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements ToCollection, WithHeadingRow
{
    protected $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            // تجاهل الصفوف الفاضية
            if (!isset($row['name'])) {
                continue;
            }

            // جلب التصنيف بالاسم
            $category = Category::where('name', $row['category'])->first();

            if (!$category) {
                // ممكن تعمل skip أو create
                continue;
            }

            Product::create([
                'user_id'     => $this->userId,
                'category_id' => $category->id,
                'name'        => $row['name'],
                'desc'        => $row['desc'] ?? null,
                'price'       => $row['price'] ?? 0,
                'quantity'    => $row['quantity'] ?? 0,
                'img'         => 'products/default.png', // صورة افتراضية
            ]);
        }
    }
}
