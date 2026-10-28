<?php

namespace App\Http\Resources\Financial;

use App\Support\Money;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * STAGE 7.5 — one line of an order, as it was actually bought.
 *
 * WHY THIS EXISTS
 *
 * Stage 7 froze the commercial terms of every order line onto `order_items`
 * (`unit_price`, `line_total`, `product_master_id`, `supplier_id`), but the API
 * kept building line values from `$item->product->price` — the supplier's LIVE,
 * editable price. Two consequences:
 *
 *   1. After a supplier repriced, an old order's lines reported the new price,
 *      so the lines no longer summed to the order's own (correct, historical)
 *      total_price.
 *   2. Those values were cast with (int), so 10.50 was reported as 10.
 *
 * THE RULE THIS RESOURCE ENFORCES
 *
 * Historical transaction data and current catalogue data are separate, and are
 * never returned under the same key:
 *
 *   HISTORICAL (frozen at purchase, never changes)
 *       unit_price · line_total · quantity · product_master_id · supplier_id
 *
 *   CURRENT (live, changes whenever the supplier edits their offer)
 *       current_product_price · quantity_available
 *
 * `price` and `total_price` are retained as aliases of the HISTORICAL values.
 * They are not new names for the old behaviour: their values are corrected.
 * On an order line "price" can only sensibly mean the price that was charged,
 * which is what they now carry. New clients should prefer `unit_price` and
 * `line_total`, whose meaning is unambiguous.
 */
class OrderItemResource extends JsonResource
{
    public function toArray($request)
    {
        $product = $this->product;

        // Frozen at purchase. historicalUnitPrice()/historicalLineTotal() fall
        // back to the product only for rows created before the snapshot columns
        // existed; for everything else they read the stored value.
        $unitPrice = (float) Money::of($this->historicalUnitPrice());
        $lineTotal = (float) Money::of($this->historicalLineTotal());

        return [
            'id' => $this->id,

            // ---------------------------------------------------- identity
            'product_id' => $this->product_id,
            // Canonical identity, recorded on the line itself so it survives the
            // supplier withdrawing or renaming their offer.
            'product_master_id' => $this->product_master_id,
            'supplier_id' => $this->supplier_id,
            // Resolved unconditionally, NOT via whenLoaded(): that returns a
            // MissingValue object, which is truthy, so a `?:` fallback would
            // never fire and the key would simply disappear on endpoints that
            // do not eager-load the relation — the same field present on one
            // list and absent on another. Prefer the line's own supplier_id
            // relation, falling back to the product's current owner for rows
            // predating the snapshot column.
            'supplier_name' => $this->supplier?->name ?? $product?->user?->name,

            // Descriptive fields come from the current catalogue row; they are
            // presentational, not financial.
            'name' => $product?->name,
            'desc' => $product?->desc,
            'img' => $product?->img,
            'category_id' => $product?->category_id === null ? null : (int) $product->category_id,
            'category_name' => $product?->category?->name,

            // ------------------------------------- HISTORICAL transaction data
            'quantity' => (int) $this->quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,

            // Legacy aliases. Same values as unit_price / line_total — corrected
            // from the live price they used to carry, and no longer truncated.
            'price' => $unitPrice,
            'total_price' => $lineTotal,

            // ------------------------------------------------------- returns
            // Cumulative APPROVED returns. Only ever grows.
            'returned_quantity' => (int) $this->returned_quantity,
            // Awaiting the supplier's decision. Non-zero means a request is
            // already open on this line.
            'pending_return_quantity' => (int) $this->pending_return_quantity,
            // Whether the supplying supplier has delivered this line. An order
            // may span several suppliers, each delivering their own lines, so
            // this is per line rather than per order.
            'is_fulfilled' => $this->isFulfilled(),
            'fulfilled_at' => $this->fulfilled_at?->format('Y-m-d'),

            // The ceiling the server will actually accept on a return request:
            // DELIVERED, less what has already been APPROVED. An undelivered
            // line is 0 — nothing has changed hands, so there is nothing to
            // return and no obligation to reverse.
            //
            // It does NOT subtract pending_return_quantity: a new request
            // REPLACES the open one rather than adding to it (one pending
            // figure and one status per line, so a second request is an
            // amendment). Subtracting it would advertise a smaller limit than
            // the server enforces and the client would disable a control that
            // works. To show "2 awaiting approval, may amend up to 5", read
            // pending_return_quantity alongside this.
            'returnable_quantity' => $this->returnableQuantity(),
            'status' => $this->status,

            // ------------------------------------------- CURRENT catalogue data
            // Deliberately NOT called `price`. This is what the supplier charges
            // today, which may differ from what this order was charged.
            'current_product_price' => $product?->price === null
                ? null
                : (float) Money::of($product->price),
            'quantity_available' => $product?->quantity === null ? null : (int) $product->quantity,
            // Retained under its original camelCase name for existing consumers.
            'quantityAvailable' => $product?->quantity,
        ];
    }
}
