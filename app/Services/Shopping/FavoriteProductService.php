<?php

namespace App\Services\Shopping;

use App\Models\Shopping\FavoriteProduct;
use App\Models\Store\Product;

class FavoriteProductService
{
    // عرض السلة
    public function index($doctor, $perPage = 10)
    {
        $favoriteProducts = FavoriteProduct::where('doctor_id', $doctor->id)
            ->paginate($perPage);
        return $favoriteProducts;
    }

    // اضافة الى المفضلة
    public function addToFavorite($doctor, $product_id, $perPage = 10)
    {
        $product = Product::findOrFail($product_id);
        if (!$product) {
            throw new \Exception('المنتج غير موجود');
        }
        $favoriteProducts = FavoriteProduct::create([
            'doctor_id' => $doctor->id,
            'product_id' => $product->id,
        ]);
        return $favoriteProducts;
    }

    // حذف منتج من المفضلة
    public function removeFromFavorite($doctor, $product_id)
    {
        $favorite = FavoriteProduct::findOrFail($product_id);

        if ($favorite->doctor_id != $doctor->id) {
            throw new \Exception('عفوا ليس لديك صلاحية الازالة من المفضلة.');
        }

        if (! $favorite) {
            throw new \Exception('هذا المنتج غير موجود في قائمة المفضلة.');
        }

        $favorite->delete();

        return true;
    }
}
