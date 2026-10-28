<?php

namespace App\Models\Shopping;

use App\Models\General\NotificationsCenter;
use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FavoriteProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'product_id',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function notificationsCenters()
    {
        return $this->morphMany(NotificationsCenter::class, 'related');
    }
}
