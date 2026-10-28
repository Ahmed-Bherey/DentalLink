<?php

namespace App\Models\Financial;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An approved product return, recorded as a financial event.
 *
 * Immutable by convention: a refund is a historical fact. Correcting one means
 * recording another, not editing this row.
 *
 * `unit_price` is copied from the order line's frozen snapshot at approval, so
 * a later price change cannot alter what was refunded.
 */
class OrderItemRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'doctor_id',
        'supplier_id',
        'quantity',
        'unit_price',
        'amount',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
