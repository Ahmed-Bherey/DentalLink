<?php

namespace App\Models\Financial;

use App\Models\General\NotificationsCenter;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id',
        'notes',
        'status',
        'payment_method',
        'price',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * Is this order fulfilled by the given supplier?
     *
     * There is no orders.supplier_id column: the selling side is expressed
     * through the line items' products. A supplier is a party to the order when
     * they own the product on at least one line.
     *
     * Used by OrderPolicy. It lives on the model because it states a fact about
     * the order's relationships, not a permission.
     */
    public function isFulfilledBy(?User $supplier): bool
    {
        if ($supplier === null) {
            return false;
        }

        return $this->orderItems()
            ->whereHas('product', fn ($q) => $q->where('user_id', $supplier->id))
            ->exists();
    }

    /**
     * The order's value, as it was agreed.
     *
     * STAGE 7: summed from each line's FROZEN snapshot, never from the product's
     * current price. Previously this multiplied `$item->product->price` — a live,
     * supplier-editable column — so an order's value changed retroactively every
     * time a supplier repriced, and collapsed to zero if the price was ever
     * nulled. It is a historical fact, not a live query.
     */
    public function getTotalOrderPriceAttribute(): float
    {
        // A PACKAGE order is sold for one agreed price held on the order
        // itself; its lines carry no individual price to sum. Summing them
        // anyway fell through to the components' CURRENT prices, so repricing
        // a component changed a package order's reported value — a 90 package
        // read as 250 after the supplier raised a component from 10 to 25.
        //
        // OrderResource happened to mask this with `$this->price ?? ...`, so
        // the defect only surfaced for callers using the accessor directly.
        if ($this->price !== null) {
            return (float) Money::of($this->price);
        }

        return (float) Money::sum(
            $this->orderItems->map(fn ($item) => $item->historicalLineTotal())
        );
    }

    /**
     * The order's value net of approved returns.
     *
     * `total_order_price` is the original contract; this is what is actually
     * owed for it now.
     */
    public function getNetOrderPriceAttribute(): float
    {
        // Same reasoning as above. A package line cannot currently be returned
        // at all (it has no individual price to refund — see
        // OrderService::updateItemStatus), so for a package order the net value
        // is simply the agreed price.
        if ($this->price !== null) {
            return (float) Money::of($this->price);
        }

        return (float) Money::sum(
            $this->orderItems->map(
                fn ($item) => Money::mul($item->historicalUnitPrice(), $item->outstandingQuantity())
            )
        );
    }

    public function notificationsCenters()
    {
        return $this->morphMany(NotificationsCenter::class, 'related');
    }

    public function getStatusNameAttribute()
    {
        return match ($this->status) {
            'pending' => 'قيد الانتظار',
            'preparing' => 'جاري التحضير',
            'delivered' => 'تم التوصيل',
            'rejected' => 'مرفوض',
            default => 'غير معروف',
        };
    }
}
