<?php

namespace App\Services\Store;

use App\Models\Catalogue\ProductMaster;
use App\Models\Store\Product;
use App\Support\RoleCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InventoryService
{
    public function getAll($user, $perPage = 10, $search = null)
    {
        return Product::where('user_id', $user->id)
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Resolve a product by id.
     *
     * NO authorization here by design (Stage 4): the caller authorizes via
     * ProductPolicy. Keeping the owner filter in the query as well would put the
     * same rule in two places, and the one in the query is the one nobody sees.
     */
    public function getById($id): ?Product
    {
        return Product::find($id);
    }

    /**
     * Create a user's holding of a CANONICAL product (Stage 7).
     *
     * The caller no longer defines a product; they pick one from the catalogue
     * and state their own terms for it. Descriptive fields default to the
     * canonical entry so a supplier does not have to retype them, and may be
     * overridden per holding.
     *
     * @throws \Exception when the owner already holds this product
     */
    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $owner = request()->user();
            $master = ProductMaster::findOrFail($data['product_master_id']);

            // One holding per owner per canonical product — the rule that stops
            // a supplier listing the same product twice. There is also a unique
            // index behind this; checking here turns a constraint violation into
            // a message the user can act on.
            $existing = Product::where('user_id', $owner->id)
                ->where('product_master_id', $master->id)
                ->first();

            if ($existing !== null) {
                throw new \Exception('هذا المنتج مضاف لديك بالفعل. يمكنك تعديل الكمية أو السعر بدلاً من إضافته مرة أخرى.');
            }

            $imgPath = null;
            if (! empty($data['img'])) {
                $imgPath = $data['img']->store('products', 'public');
            }

            $product = Product::create([
                'user_id' => $owner->id,
                'product_master_id' => $master->id,
                // Inherited from the canonical entry unless overridden.
                'name' => $data['name'] ?? $master->name,
                'category_id' => $data['category_id'] ?? $master->category_id,
                'img' => $imgPath ?? $master->getRawOriginal('img'),
                'desc' => $data['desc'] ?? $master->desc,
                'price' => $data['price'],
                'base_price' => $data['base_price'] ?? null,
                'quantity' => $data['quantity'],
            ]);

            return [
                'product' => $product,
            ];
        });
    }

    /**
     * Update an already-resolved, already-authorized product.
     *
     * Authorization is the caller's job (ProductPolicy::update).
     */
    public function update(Product $product, array $data)
    {
        return DB::transaction(function () use ($product, $data) {

            // معالجة الصورة إن وجدت
            // معالجة الصورة إن وجدت وكانت ملف فعلي
            if (array_key_exists('img', $data)) {
                if ($data['img'] instanceof \Illuminate\Http\UploadedFile) {
                    // حذف الصورة القديمة إن وجدت
                    if (! empty($product->img) && Storage::disk('public')->exists($product->img)) {
                        Storage::disk('public')->delete($product->img);
                    }

                    // رفع الصورة الجديدة
                    $data['img'] = $data['img']->store('products', 'public');
                } elseif (is_string($data['img'])) {
                    // صورة لم تتغير - لا حاجة لفعل أي شيء (الاسم سيُستبدل بنفسه)
                    // إذا أردت، يمكنك التحقق أنه يساوي $product->img لضمان عدم التلاعب
                } else {
                    // نوع غير صالح للصورة - احذفه
                    unset($data['img']);
                }
            } else {
                // img لم تُرسل أساسًا
                unset($data['img']);
            }

            $product->update($data);

            return [
                'product' => $product,
            ];
        });
    }

    /**
     * Delete an already-resolved, already-authorized product.
     *
     * Stage 5: order_items.product_id is now ON DELETE RESTRICT, so a product
     * that appears in any order can no longer be deleted. Previously this
     * succeeded and cascaded — silently removing lines from doctors' historical
     * orders and changing what those orders totalled.
     *
     * The check below turns that database refusal into the same kind of message
     * CategoryService already gives for a category that still has products,
     * rather than letting an SQL constraint error surface to the client.
     */
    public function delete(Product $product)
    {
        return DB::transaction(function () use ($product) {
            $this->assertNotUsedInAnyOrder($product);

            // حذف الصورة من التخزين إن وجدت
            if ($product->img && Storage::disk('public')->exists($product->img)) {
                Storage::disk('public')->delete($product->img);
            }

            $product->delete();

            return true;
        });
    }

    /**
     * A product that has been ordered is part of somebody's purchase history.
     *
     * Deleting it is refused outright rather than soft-deleted or anonymised:
     * introducing soft deletes is a domain change, and this stage only closes
     * the hole. The image is left in storage too, since the product row stays.
     *
     * @throws \Exception
     */
    private function assertNotUsedInAnyOrder(Product $product): void
    {
        $orderedCount = $product->orderItems()->count();

        if ($orderedCount === 0) {
            return;
        }

        throw new \Exception(
            'لا يمكن حذف المنتج لأنه مستخدم في '.$orderedCount.' من سجلات الطلبات. '
            .'يمكنك إخفاؤه أو تعديل الكمية بدلاً من حذفه.'
        );
    }

    /**
     * Delete a batch of already-resolved, already-authorized products.
     *
     * The caller authorizes EVERY product before calling, and refuses the whole
     * batch if any one fails - never a partial delete.
     */
    public function multiDelete($products)
    {
        return DB::transaction(function () use ($products) {
            // Check the whole batch before touching anything. Storage deletions
            // are not covered by the transaction, so discovering an ordered
            // product halfway through would already have removed earlier images
            // with no way to roll them back.
            foreach ($products as $product) {
                $this->assertNotUsedInAnyOrder($product);
            }

            foreach ($products as $product) {
                // حذف الصورة من التخزين إن وجدت
                if ($product->img && Storage::disk('public')->exists($product->img)) {
                    Storage::disk('public')->delete($product->img);
                }

                $product->delete();
            }

            return true;
        });
    }

    // عرض قائمة منتجات كل الموردين للطبيب
    public function getAllSuppliersProducts($filters = [], $doctor = null)
    {
        $query = Product::with(['user.role', 'category'])
            ->whereHas('user.role', function ($q) {
                $q->where('code', '!=', RoleCode::DOCTOR);
            });

        if ($doctor) {
            $query->with([
                'favoriteProducts' => fn ($q) => $q->where('doctor_id', $doctor->id),
                'carts' => fn ($q) => $q->where('doctor_id', $doctor->id),
            ]);
        }

        // 🔍 فلتر بحث عام باسم المنتج أو المورد
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // 🗂️ فلتر بالتصنيف
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        // ↕️ فلتر ترتيب السعر (الأعلى أو الأقل)
        if (! empty($filters['sort']) && in_array($filters['sort'], ['asc', 'desc'])) {
            $query->orderBy('price', $filters['sort']);
        } else {
            $query->latest();
        }

        return $query->paginate(10);
    }

    // البحث
    public function search($user, $search)
    {
        return Product::where('user_id', $user->id)
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
