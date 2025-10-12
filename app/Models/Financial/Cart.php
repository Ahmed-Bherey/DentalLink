<?php

namespace App\Models\Financial;

use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Cart extends Model
{
    use HasFactory;
    protected $fillable = [
        'doctor_id',
        'product_id',
        'quantity',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
