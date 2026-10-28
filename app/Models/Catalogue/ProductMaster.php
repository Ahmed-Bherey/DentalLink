<?php

namespace App\Models\Catalogue;

use App\Models\General\Category;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A canonical product in the shared catalogue.
 *
 * Managed by the Super Admin. Suppliers and doctors do not create these; they
 * SELECT one and create their own `Product` holding of it, which is where price
 * and stock live. See ProductMasterPolicy.
 *
 * There is deliberately no price here. A canonical product has no price — only a
 * supplier's offer of it does. Keeping price out is what makes the same product
 * comparable across suppliers.
 */
class ProductMaster extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category_id',
        'desc',
        'img',
        'unit',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every holding of this product — supplier offers and doctor inventory alike.
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'product_master_id');
    }

    /**
     * Supplier offers only, which is what a doctor browsing the catalogue wants.
     */
    public function offers()
    {
        return $this->products()->whereHas('user', fn ($q) => $q->whereHas('role', fn ($r) => $r->where('code', 'supplier')));
    }

    public function getImgAttribute($value)
    {
        return $value ? asset('storage/'.$value) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Free-text search for the product picker.
     *
     * The business rule is that a supplier or doctor SELECTS an existing
     * canonical product rather than inventing one. That requires the catalogue
     * to be searchable; which control the frontend uses (dropdown, autocomplete,
     * modal) is not this layer's concern.
     */
    public function scopeSearch($query, ?string $term)
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        return $query->where('name', 'like', '%'.trim($term).'%');
    }
}
