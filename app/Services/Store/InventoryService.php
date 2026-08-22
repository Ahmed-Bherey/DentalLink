<?php

namespace App\Services\Store;

use App\Models\Store\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Auth\Access\AuthorizationException;

class InventoryService
{
    public function getAll($user, $perPage = 10, $search = null)
    {
        return Product::where('user_id', $user->id)
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    // عرض منتج واحد
    // Security (Stage 1): scoped to the owner. Previously `$user` was accepted and
    // ignored, so any authenticated user could read any product by id (IDOR).
    public function getById($user, $id)
    {
        return Product::where('id', $id)
            ->where('user_id', $user->id)
            ->first();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $imgPath = null;
            if (!empty($data['img'])) {
                $img = $data['img'];
                $imgPath = $img->store('products', 'public');
            }

            $product = Product::create([
                'user_id'    => request()->user()->id,
                'name'  => $data['name'],
                'category_id'  => $data['category_id'],
                'img'  => $imgPath,
                'desc'  => $data['desc'],
                'price' => $data['price'],
                'quantity'   => $data['quantity'],
            ]);

            return [
                'product'   => $product,
            ];
        });
    }

    // تحديث المنتج
    // Security (Stage 1): the owner scope is enforced in the query. Previously any
    // authenticated user could rewrite any product (price, quantity, name) by id.
    public function update($user, int $productId, array $data)
    {
        return DB::transaction(function () use ($user, $productId, $data) {
            $product = Product::where('user_id', $user->id)->findOrFail($productId);

            // معالجة الصورة إن وجدت
            // معالجة الصورة إن وجدت وكانت ملف فعلي
            if (array_key_exists('img', $data)) {
                if ($data['img'] instanceof \Illuminate\Http\UploadedFile) {
                    // حذف الصورة القديمة إن وجدت
                    if (!empty($product->img) && Storage::disk('public')->exists($product->img)) {
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

    // حذف منتج واحد
    // Security (Stage 1): owner-scoped. Product deletion cascades into order_items,
    // so an unscoped delete destroyed other suppliers' historical order lines.
    public function delete($user, int $productId)
    {
        return DB::transaction(function () use ($user, $productId) {
            $product = Product::where('user_id', $user->id)->findOrFail($productId);

            // حذف الصورة من التخزين إن وجدت
            if ($product->img && Storage::disk('public')->exists($product->img)) {
                Storage::disk('public')->delete($product->img);
            }

            $product->delete();

            return true;
        });
    }

    // حذف مجموعة منتجات
    // Security (Stage 1): owner-scoped. Previously an unbounded id array could
    // delete any supplier's catalog in a single request.
    public function multiDelete($user, array $productIds)
    {
        return DB::transaction(function () use ($user, $productIds) {
            $products = Product::whereIn('id', $productIds)
                ->where('user_id', $user->id)
                ->get();

            // Refuse the whole batch if it references products the caller does not own,
            // rather than silently deleting the subset they do own.
            if ($products->count() !== count(array_unique($productIds))) {
                throw new AuthorizationException('عفوا، ليس لديك صلاحية حذف أحد المنتجات المحددة.');
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
        $query = Product::with(['user.department', 'category'])
            ->whereHas('user.department', function ($q) {
                $q->where('code', '!=', 'doctor');
            });

        if ($doctor) {
            $query->with([
                'favoriteProducts' => fn($q) => $q->where('doctor_id', $doctor->id),
                'carts' => fn($q) => $q->where('doctor_id', $doctor->id),
            ]);
        }

        // 🔍 فلتر بحث عام باسم المنتج أو المورد
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // 🗂️ فلتر بالتصنيف
        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        // ↕️ فلتر ترتيب السعر (الأعلى أو الأقل)
        if (!empty($filters['sort']) && in_array($filters['sort'], ['asc', 'desc'])) {
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
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
