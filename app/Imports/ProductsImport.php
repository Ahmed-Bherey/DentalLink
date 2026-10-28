<?php

namespace App\Imports;

use App\Models\Catalogue\ProductMaster;
use App\Models\General\Category;
use App\Models\Store\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements ShouldQueue, ToCollection, WithChunkReading, WithHeadingRow
{
    public function chunkSize(): int
    {
        return 500;
    }

    protected int $userId;

    /**
     * Rows whose product name matched no canonical product.
     *
     * STAGE 7: the import RESOLVES a canonical product by name; it never
     * creates one. Letting a spreadsheet mint catalogue entries would hand
     * every supplier the Super Admin's job through the side door, which is
     * exactly the duplication the Product Master exists to end.
     *
     * @var string[]
     */
    protected array $unmatched = [];

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Product names in the spreadsheet that are not in the catalogue.
     *
     * @return string[]
     */
    public function unmatchedProducts(): array
    {
        return array_values(array_unique($this->unmatched));
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {

            // تجاهل الصفوف غير الصالحة
            if (empty($row['name']) || empty($row['category'])) {
                continue;
            }

            $productName = trim($row['name']);
            $categoryName = trim($row['category']);

            /** =============================
             *  📂 التصنيف
             *  ============================= */
            $category = Category::where('name', 'LIKE', "%{$categoryName}%")->first();

            if (! $category) {
                $category = Category::create([
                    'user_id' => $this->userId,
                    'name' => $categoryName,
                    'desc' => 'Imported from Excel',
                    'img' => 'products/default.jpg',
                ]);
            }

            /** =============================
             *  🦷 المنتج
             *  ============================= */
            // STAGE 7: the row must correspond to an EXISTING canonical
            // product. Matching is exact (case-insensitive, trimmed) rather
            // than a LIKE, because a partial match would silently attach a
            // supplier's stock to the wrong product.
            $master = ProductMaster::query()
                ->active()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($productName)])
                ->first();

            if ($master === null) {
                // Collected and reported rather than skipped in silence, so the
                // supplier learns which products need adding to the catalogue.
                $this->unmatched[] = $productName;

                continue;
            }

            // One holding per owner per canonical product, matched by IDENTITY.
            // The previous LIKE match on name could attach a row to the wrong
            // product, and created a duplicate whenever a name was edited.
            $product = Product::where('user_id', $this->userId)
                ->where('product_master_id', $master->id)
                ->first();

            $data = [
                'user_id' => $this->userId,
                'product_master_id' => $master->id,
                'category_id' => $category->id,
                'name' => $productName,
                'desc' => $row['desc'] ?? null,
                'price' => (float) ($row['price'] ?? 0),
                'quantity' => (int) ($row['quantity'] ?? 0),
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
        $newImagePath = 'products/'.Str::uuid().'.jpg';

        Storage::disk('public')->copy(
            'products/default.jpg',
            $newImagePath
        );

        return $newImagePath;
    }
}
