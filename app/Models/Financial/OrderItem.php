<?php

namespace App\Models\Financial;

use App\Models\Catalogue\ProductMaster;
use App\Models\General\NotificationsCenter;
use App\Models\Store\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_master_id',
        'supplier_id',
        'quantity',
        'unit_price',
        'line_total',
        'status',
        // Cumulative APPROVED returns. Only grows.
        'returned_quantity',
        // The quantity currently awaiting the supplier's decision.
        'pending_return_quantity',
        // When the supplying supplier delivered this line (null = not yet).
        'fulfilled_at',
    ];

    protected $casts = [
        'fulfilled_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * The canonical product this line refers to.
     *
     * Recorded on the line itself so the line survives the supplier withdrawing,
     * renaming or re-pointing their offer.
     */
    public function productMaster()
    {
        return $this->belongsTo(ProductMaster::class, 'product_master_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function refunds()
    {
        return $this->hasMany(OrderItemRefund::class, 'order_item_id');
    }

    // ------------------------------------------------- historical price (Stage 7)

    /**
     * The unit price actually charged for this line.
     *
     * ALWAYS use this, never `$item->product->price`. The product's price is
     * live and mutable; this is the figure the doctor agreed to. Falling back to
     * the product only covers rows created before the snapshot column existed.
     */
    public function historicalUnitPrice(): string
    {
        if ($this->unit_price !== null) {
            return Money::of($this->unit_price);
        }

        return Money::of(optional($this->product)->price);
    }

    /**
     * The original contract value of this line — what was purchased, at the
     * price charged, BEFORE any returns.
     *
     * Stored rather than recomputed because `returned_quantity` accumulates
     * against this line; recomputing from a live quantity would give a different
     * answer after every approved return.
     */
    public function historicalLineTotal(): string
    {
        if ($this->line_total !== null) {
            return Money::of($this->line_total);
        }

        return Money::mul($this->historicalUnitPrice(), (int) $this->quantity);
    }

    /**
     * How many units of this line have not yet been returned.
     *
     * `quantity` is the immutable purchased quantity as of Stage 7; before that
     * an approved return decremented it, which destroyed the record of what was
     * originally bought and made this calculation impossible.
     */
    public function outstandingQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) ($this->returned_quantity ?? 0));
    }

    // ------------------------------------------------------------ fulfilment

    /**
     * Has the supplying supplier actually delivered this line?
     *
     * Fulfilment is tracked per LINE, not per order: an order may contain lines
     * from several suppliers, each of whom delivers their own.
     */
    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }

    /**
     * How many units have actually been delivered.
     *
     * Fulfilment is all-or-nothing per line, so this is the full quantity once
     * the line is fulfilled and zero before that.
     */
    public function fulfilledQuantity(): int
    {
        return $this->isFulfilled() ? (int) $this->quantity : 0;
    }

    /**
     * How many units may still be returned.
     *
     * Delivered, less what has already been APPROVED for return. An
     * undelivered line returns 0 — nothing has changed hands, so there is no
     * obligation to reverse (C-3). This is the single definition the request
     * path, the approval path and the API resource all use.
     */
    public function returnableQuantity(): int
    {
        return max(0, $this->fulfilledQuantity() - (int) ($this->returned_quantity ?? 0));
    }

    /**
     * Is the product on this line supplied by the given user?
     *
     * Fails closed when product_id is null (the column is nullable — a Stage 5
     * integrity issue): an unattributable line has no supplier.
     */
    public function isSuppliedBy(?User $supplier): bool
    {
        if ($supplier === null) {
            return false;
        }

        $product = $this->product;

        return $product !== null && (int) $product->user_id === (int) $supplier->id;
    }

    public function notificationsCenters()
    {
        return $this->morphMany(NotificationsCenter::class, 'related');
    }
}
