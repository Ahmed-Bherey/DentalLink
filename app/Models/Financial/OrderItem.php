<?php

namespace App\Models\Financial;

use App\Models\Store\Product;
use App\Models\Store\Inventory;
use Illuminate\Database\Eloquent\Model;
use App\Models\General\NotificationsCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderItem extends Model
{
    use HasFactory;
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'status',
        'returned_quantity',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
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
