<?php

namespace Database\Factories\Financial;

use App\Models\Financial\Order;
use App\Models\Financial\OrderItem;
use App\Models\Store\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 *
 * NOTE: order_items has NO price column. Line value is derived from the linked
 * product's CURRENT price every time it is read.
 * => PRICE SNAPSHOT DEFECT — DEFERRED TO LATER STAGE.
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'quantity' => 2,
            // STAGE 7: a real order line always carries a snapshot, so the
            // factory must too. Leaving these null would let tests pass through
            // the legacy fallback path and silently exercise the OLD behaviour.
            'unit_price' => 100,
            'line_total' => 200,
            'status' => 'confirmed',
            // STAGE 7: cumulative APPROVED returns. Starts at zero, only grows.
            'returned_quantity' => 0,
            'pending_return_quantity' => 0,
        ];
    }

    /**
     * A line awaiting the supplier's decision on a return REQUEST.
     *
     * The requested amount goes to pending_return_quantity; returned_quantity
     * stays at zero because nothing has been approved yet. Before Stage 7 one
     * column held both meanings, which is why the cumulative approved total was
     * unrecoverable.
     */
    public function pendingReturn(int $quantity): static
    {
        return $this->state(fn () => [
            'status' => 'delete_pending',
            'pending_return_quantity' => $quantity,
        ]);
    }

    /**
     * Attach the line to a specific offer, copying its price into the snapshot
     * exactly as OrderService::store() does at purchase time.
     */
    public function forOffer(Product $offer, int $quantity = 1): static
    {
        return $this->state(fn () => [
            'product_id' => $offer->id,
            'product_master_id' => $offer->product_master_id,
            'supplier_id' => $offer->user_id,
            'quantity' => $quantity,
            'unit_price' => $offer->price,
            'line_total' => bcmul((string) $offer->price, (string) $quantity, 2),
        ]);
    }

    /**
     * The supplying supplier has delivered this line.
     */
    public function fulfilled(): static
    {
        return $this->state(fn () => ['fulfilled_at' => now()]);
    }

    /**
     * An explicit historical price, independent of any current product price.
     */
    public function snapshot(float $unitPrice, int $quantity): static
    {
        return $this->state(fn () => [
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => bcmul((string) $unitPrice, (string) $quantity, 2),
        ]);
    }
}
