<?php

namespace App\Services\Shopping;

use App\Models\FcmToken;
use App\Models\Store\Product;
use App\Models\Shopping\FavoriteProduct;
use App\Services\Notifaction\FirebaseService;
use App\Services\Notifaction\NotificationService;

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
            'user_id'  => $product->user_id, // المورد
            'title'    => 'إضافة إلى المفضلة',
            'message'  => "قام الطبيب {$doctor->name} بإضافة منتجك إلى المفضلة ❤️<br>"
                . "🔸 المنتج: \"{$product->name}\"",
            'type'     => 'heart',
            'color'    => 'pink',
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
