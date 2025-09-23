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
            if (!empty($data['img'])) {
                // حذف الصورة القديمة إن وجدت
                if ($product->img && Storage::disk('public')->exists($product->img)) {
                    Storage::disk('public')->delete($product->img);
                }

                // رفع الصورة الجديدة
                $img = $data['img'];
                $data['img'] = $img->store('products', 'public');
            } else {
                unset($data['img']); // تجنب تعديل الحقل إذا لم يتم رفع صورة جديدة
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
}
