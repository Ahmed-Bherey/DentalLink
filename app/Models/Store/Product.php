<?php

namespace App\Models\Store;

use App\Models\Catalogue\ProductMaster;
use App\Models\Financial\Cart;
use App\Models\Financial\OrderItem;
use App\Models\Financial\PackageItem;
use App\Models\General\Category;
use App\Models\Shopping\FavoriteProduct;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    /**
     * STAGE 7 — this row is now "one user's holding of one canonical product".
     *
     *   supplier row = an OFFER      (base_price = cost, price = selling price)
     *   doctor row   = INVENTORY     (price carries what was paid, for display)
     *
     * `price` is deliberately NOT renamed to selling_price: it already means
     * "what the buyer pays" throughout the resources, the Excel import/export
     * and the order totals. New meaning arrives as a new column instead of by
     * redefining an existing one.
     */
    protected $fillable = [
        'user_id',
        'product_master_id',
        'category_id',
        'name',
        'img',
        'desc',
        'price',
        'base_price',
        'quantity',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The canonical product this holding refers to.
     *
     * Every offer and every inventory row points here, which is what makes the
     * same product comparable across suppliers and findable in a doctor's stock
     * without matching on name.
     */
    public function productMaster()
    {
        return $this->belongsTo(ProductMaster::class, 'product_master_id');
    }

    /**
     * The supplier's margin on this offer, or null when base price is unknown.
     *
     * Presentation helper only - no financial decision reads it.
     */
    public function margin(): ?string
    {
        if ($this->base_price === null || $this->price === null) {
            return null;
        }

        return Money::sub($this->price, $this->base_price);
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
        return $value ? asset('storage/'.$value) : null;
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
