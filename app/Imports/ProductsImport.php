<?php

namespace App\Imports;

use Illuminate\Support\Str;
use App\Models\Store\Product;
use App\Models\General\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ProductsImport implements ToCollection, WithHeadingRow, ShouldQueue, WithChunkReading
{
    public function chunkSize(): int
    {
        return 500;
    }
    protected int $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            // تجاهل الصفوف غير الصالحة
            if (empty($row['name']) || empty($row['category'])) {
                continue;
            }

            $productName  = trim($row['name']);
            $categoryName = trim($row['category']);

            /** =============================
             *  📂 التصنيف
             *  ============================= */
            $category = Category::where('name', 'LIKE', "%{$categoryName}%")->first();

            if (!$category) {
                $category = Category::create([
                    'user_id' => $this->userId,
                    'name'    => $categoryName,
                    'desc'    => 'Imported from Excel',
                    'img'     => 'products/default.jpg',
                ]);
            }

            /** =============================
             *  🦷 المنتج
             *  ============================= */
            $product = Product::where('user_id', $this->userId)
                ->where('name', 'LIKE', "%{$productName}%")
                ->first();

            $data = [
                'user_id'     => $this->userId,
                'category_id' => $category->id,
                'name'        => $productName,
                'desc'        => $row['desc'] ?? null,
                'price'       => (float) ($row['price'] ?? 0),
                'quantity'    => (int) ($row['quantity'] ?? 0),
            ];

            if ($product) {
                // 🔄 تحديث كامل (مش زيادة)
                $product->update($data);
            } else {
                // ➕ إنشاء جديد
                $data['img'] = $this->uploadDefaultImage();
                Product::create($data);
            }
        }
    }

    private function uploadDefaultImage(): string
    {
        $newImagePath = 'products/' . Str::uuid() . '.jpg';

        Storage::disk('public')->copy(
            'products/default.jpg',
            $newImagePath
        );

        return $newImagePath;
    }
}
