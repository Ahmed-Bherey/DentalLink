<?php

namespace App\Models\Store;

use App\Models\User;
use App\Models\Financial\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'name',
        'img',
        'desc',
        'price',
        'quantity',
    ];

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }

    public function getImgAttribute($value)
    {
        return $value ? asset('storage/' . $value) : null;
    }
}
