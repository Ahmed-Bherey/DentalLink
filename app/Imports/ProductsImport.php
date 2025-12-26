<?php

namespace App\Imports;

use App\Models\Store\Product;
use App\Models\General\Category;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Str;

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
            if (empty($row['name'])) {
                continue;
            }

            // تنظيف اسم التصنيف
            $categoryName = trim($row['category'] ?? '');

            if (!$categoryName) {
                continue;
            }

            // 🔍 البحث عن تصنيف باسم مشابه
            $category = Category::where('name', 'LIKE', '%' . $categoryName . '%')->first();

            // ➕ لو مش موجود → نعمل Create
            if (!$category) {
                $category = Category::create([
                    'user_id' => $this->userId, // أو null لو التصنيفات عامة
                    'name'    => $categoryName,
                    'desc'    => 'Imported from Excel',
                    'img'     => 'categories/default.png',
                ]);
            }

            // إنشاء المنتج
            Product::create([
                'user_id'     => $this->userId,
                'category_id' => $category->id,
                'name'        => trim($row['name']),
                'desc'        => $row['desc'] ?? null,
                'price'       => (float) ($row['price'] ?? 0),
                'quantity'    => (int) ($row['quantity'] ?? 0),
                'img'         => 'products/default.png',
            ]);
        }
    }
}
