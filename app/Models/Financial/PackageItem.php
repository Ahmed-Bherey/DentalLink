<?php

namespace App\Models\Financial;

use App\Models\Store\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PackageItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'product_id',
        'quantity',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
