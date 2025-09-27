<?php

namespace App\Services\Store;

use App\Models\Store\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InventoryService
{
    public function getAll($user)
    {
        return Product::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')->get();
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
    public function update(int $productId, array $data)
    {
        return DB::transaction(function () use ($productId, $data) {
            $product = Product::findOrFail($productId);

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
    public function delete(int $productId)
    {
        return DB::transaction(function () use ($productId) {
            $product = Product::findOrFail($productId);

            // حذف الصورة من التخزين إن وجدت
            if ($product->img && Storage::disk('public')->exists($product->img)) {
                Storage::disk('public')->delete($product->img);
            }

            $product->delete();

            return true;
        });
    }

    // حذف مجموعة منتجات
    public function multiDelete(array $productIds)
    {
        return DB::transaction(function () use ($productIds) {
            $products = Product::whereIn('id', $productIds)->get();

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
    public function getAllSuppliersProducts()
    {
        return Product::whereHas('user.department', function ($q) {
            $q->where('code', '!=', 'doctor');
        })
            ->latest()
            ->get();
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
