<?php

namespace App\Models\Financial;

use App\Models\User;
use App\Models\Financial\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'doctor_id',
        'notes',
        'status',
        'payment_method',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function getTotalOrderPriceAttribute(): float
    {
        return $this->orderItems->sum(function ($item) {
            return optional($item->product)->price * $item->quantity;
        });
    }

    public function getStatusNameAttribute()
    {
        return match ($this->status) {
            'pending'   => 'قيد الانتظار',
            'preparing' => 'جاري التحضير',
            'delivered' => 'تم التوصيل',
            'rejected'  => 'مرفوض',
            default     => 'غير معروف',
        };
    }
}
