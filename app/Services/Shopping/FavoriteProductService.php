<?php

namespace App\Services\Shopping;

use App\Models\FcmToken;
use App\Models\Shopping\FavoriteProduct;
use App\Models\Store\Product;
use App\Services\Notifaction\FirebaseService;

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

        // تحقق من عدم تكرار الإضافة
        $exists = FavoriteProduct::where('doctor_id', $doctor->id)
            ->where('product_id', $product->id)
            ->exists();

        if ($exists) {
            throw new \Exception('المنتج مضاف مسبقًا إلى المفضلة.');
        }

        $favoriteProducts = FavoriteProduct::create([
            'doctor_id' => $doctor->id,
            'product_id' => $product->id,
        ]);

        // إرسال إشعار للمورد أن الطبيب أضاف منتجه إلى المفضلة
        $favoriteProducts->notificationsCenters()->create([
            'user_id' => $product->user_id, // المورد
            'title' => 'إضافة إلى المفضلة',
            'message' => "قام الطبيب {$doctor->name} بإضافة منتجك إلى المفضلة ❤️<br>"
                ."🔸 المنتج: \"{$product->name}\"",
            'type' => 'heart',
            'color' => 'pink',
        ]);

        // $tokens = FcmToken::where('user_id', $product->user_id)->pluck('fcm_token');
        // $firebase = new FirebaseService();
        // foreach ($tokens as $token) {
        //     $firebase->send(
        //         'إضافة إلى المفضلة',
        //         'قام الطبيب ' . $doctor->name . ' بإضافة منتجك "' . $product->name . '" إلى المفضلة.',
        //         $token,
        //         '/operations/favorites'
        //     );
        // }

        return $favoriteProducts;
    }

    // حذف منتج من المفضلة
    /**
     * Remove an already-resolved, already-authorized favorite.
     *
     * NOTE (deferred): the route parameter is named {product_id} but has always
     * been treated as the FAVORITE row id. That naming bug is unchanged here -
     * fixing it is an API-contract change, not an authorization change.
     * Authorization now covers whichever record the id actually resolves to.
     */
    public function removeFromFavorite(FavoriteProduct $favorite)
    {
        $favorite->delete();

        return true;
    }
}
