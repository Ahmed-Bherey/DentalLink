<?php

namespace App\Models\Store;

use App\Models\Financial\Cart;
use App\Models\User;
use App\Models\General\Category;
use App\Models\Financial\OrderItem;
use App\Models\Financial\PackageItem;
use App\Models\Shopping\FavoriteProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'img',
        'desc',
        'price',
        'quantity',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function getImgAttribute($value)
    {
        return $value ? asset('storage/' . $value) : null;
    }

    public function packageItems()
    {
        return $this->hasMany(PackageItem::class, 'product_id');
    }

    public function carts()
    {
        return $this->hasMany(Cart::class, 'product_id');
    }

    public function favoriteProducts()
    {
        return $this->hasMany(FavoriteProduct::class, 'product_id');
    }
}
