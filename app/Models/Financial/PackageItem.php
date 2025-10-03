<?php

namespace App\Models\Financial;

use App\Models\Store\Product;
use App\Models\Financial\Package;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PackageItem extends Model
{
    use HasFactory;
    protected $fillable = [
        'package_id',
        'product_id',
        'quantity'
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
