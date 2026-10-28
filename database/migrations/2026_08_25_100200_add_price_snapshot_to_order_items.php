<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STAGE 7 — freeze the commercial terms of an order line at purchase time.
 *
 * THE DEFECT
 *
 * `order_items` stored only order_id, product_id and quantity. Every monetary
 * value derived from it read `products.price` — a MUTABLE column the supplier
 * can edit at any moment. So:
 *
 *   - an order's total changed retroactively when the supplier repriced
 *   - a refund credited the CURRENT price, not the price actually charged
 *   - editing a product silently rewrote financial history
 *
 * Stage 6 identified this and could not fix it: correcting it requires changing
 * how price is stored, which was reserved for this stage.
 *
 * WHAT IS FROZEN, AND WHY EACH FIELD
 *
 *   unit_price          what one unit actually cost in this transaction.
 *   quantity            how many were purchased. See the semantics note below.
 *   line_total          unit_price x quantity at purchase.
 *   product_master_id   canonical identity, so the line remains meaningful even
 *                       if the supplier later deletes their offer or renames it.
 *   supplier_id         who sold it. Previously derived by walking
 *                       orderItem -> product -> user_id, which changes if the
 *                       product's owner ever does, and breaks entirely if the
 *                       product row is gone.
 *
 * `line_total` is stored even though it equals unit_price x quantity today.
 * That is not redundancy: `returned_quantity` accumulates against this line, and
 * the ORIGINAL contract value must stay readable after any number of partial
 * returns. Recomputing it from a live quantity would give a different answer
 * every time a return is approved.
 *
 * QUANTITY SEMANTICS CHANGE
 *
 * Previously an approved return DECREMENTED `order_items.quantity`, destroying
 * the record of how much was originally bought and making "a return may not
 * exceed the purchase" impossible to check. From now on `quantity` is the
 * IMMUTABLE purchased quantity and `returned_quantity` is the cumulative
 * approved total; the outstanding quantity is the difference.
 *
 * BACKFILL
 *
 * Existing rows have no recorded price, so the only available value is the
 * product's current one. That is explicitly a best-effort reconstruction, not a
 * true snapshot — the real historical price is not recoverable because it was
 * never stored. It is applied only to rows that already exist, is reported, and
 * every row created after this migration carries a real snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_master_id')->nullable()->after('product_id');
            $table->unsignedBigInteger('supplier_id')->nullable()->after('product_master_id');
            $table->decimal('unit_price', 12, 2)->nullable()->after('quantity');
            $table->decimal('line_total', 12, 2)->nullable()->after('unit_price');
        });

        $this->backfillSnapshots();

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_master_id')->references('id')->on('product_masters')
                ->onUpdate('cascade')->onDelete('restrict');

            // The selling party must outlive the sale, exactly as Stage 5
            // established for the buying party on `orders`.
            $table->foreign('supplier_id')->references('id')->on('users')
                ->onUpdate('cascade')->onDelete('restrict');
        });

        $this->separateRequestedFromApprovedReturns();

        // A purchase of zero or a negative number of units is not a purchase.
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_quantity_positive CHECK (quantity > 0)');

        // The invariant the return flow depends on: you cannot give back more
        // than you bought. Enforced here as well as in the service, because a
        // service check alone is one console command away from being bypassed.
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_returned_within_purchased CHECK (returned_quantity >= 0 AND returned_quantity <= quantity)');

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_unit_price_non_negative CHECK (unit_price IS NULL OR unit_price >= 0)');
    }

    public function down(): void
    {
        foreach ([
            'chk_order_items_quantity_positive',
            'chk_order_items_returned_within_purchased',
            'chk_order_items_unit_price_non_negative',
        ] as $constraint) {
            try {
                DB::statement("ALTER TABLE order_items DROP CONSTRAINT `{$constraint}`");
            } catch (\Throwable $e) {
                DB::statement("ALTER TABLE order_items DROP CHECK `{$constraint}`");
            }
        }

        // Restore the overloaded single column: any outstanding request moves
        // back into it, since that is what it used to mean.
        DB::statement('ALTER TABLE order_items MODIFY returned_quantity INT NULL');
        DB::statement("UPDATE order_items SET returned_quantity = pending_return_quantity WHERE status = 'delete_pending'");

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_master_id']);
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['product_master_id', 'supplier_id', 'unit_price', 'line_total', 'pending_return_quantity']);
        });
    }

    /**
     * Split one overloaded column into the two distinct facts it was holding.
     *
     * `returned_quantity` previously meant "the quantity the doctor has ASKED to
     * return", was set when the request was made, and was reset to NULL when the
     * supplier decided. So it recorded a pending request, and the cumulative
     * total actually returned was recorded NOWHERE — which is precisely why
     * "a return may not exceed the purchase" could not be enforced across
     * multiple returns.
     *
     * After this migration:
     *
     *   pending_return_quantity   awaiting the supplier's decision (0 = none)
     *   returned_quantity         cumulative APPROVED returns, only ever grows
     *
     * Existing values are requests, not approvals, so they move to the pending
     * column. Rows that are not awaiting a decision had a stale value left over
     * from a previous cycle and are cleared.
     *
     * Approved-return history before this point is unrecoverable: it was
     * overwritten by design. Existing rows therefore start from zero approved,
     * which is the only defensible reading of "no record exists".
     */
    private function separateRequestedFromApprovedReturns(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('pending_return_quantity')->default(0)->after('returned_quantity');
        });

        // A pending request is only meaningful while the line awaits a decision.
        DB::statement("
            UPDATE order_items
               SET pending_return_quantity = LEAST(COALESCE(returned_quantity, 0), quantity)
             WHERE status = 'delete_pending'
        ");

        // The column now means 'cumulative approved', of which there is no record.
        DB::statement('UPDATE order_items SET returned_quantity = 0');
        DB::statement('ALTER TABLE order_items MODIFY returned_quantity INT NOT NULL DEFAULT 0');

        $pending = DB::table('order_items')->where('pending_return_quantity', '>', 0)->count();

        if ($pending > 0) {
            echo "  [Stage 7] Moved {$pending} outstanding return request(s) to pending_return_quantity; "
                ."returned_quantity now means cumulative APPROVED returns and starts at 0.\n";
        }
    }

    /**
     * Reconstruct a snapshot for rows that predate this column.
     *
     * @throws RuntimeException
     */
    private function backfillSnapshots(): void
    {
        $rows = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                'order_items.id',
                'order_items.quantity',
                'products.price',
                'products.user_id AS supplier_id',
                'products.product_master_id'
            )
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $withoutPrice = 0;

        foreach ($rows as $row) {
            // A product with no price yields a zero-value line rather than a
            // NULL one. Zero is wrong, but it is a KNOWN wrong that keeps the
            // arithmetic total-able; NULL would silently poison every sum.
            $unit = $row->price === null ? '0.00' : (string) $row->price;

            if ($row->price === null) {
                $withoutPrice++;
            }

            DB::table('order_items')->where('id', $row->id)->update([
                'unit_price' => $unit,
                'line_total' => bcmul($unit, (string) max(1, (int) $row->quantity), 2),
                'supplier_id' => $row->supplier_id,
                'product_master_id' => $row->product_master_id,
            ]);
        }

        echo "\n  [Stage 7] Reconstructed a price snapshot for {$rows->count()} existing order line(s) "
            ."from the product's CURRENT price — the true historical price was never stored.\n";

        if ($withoutPrice > 0) {
            echo "  [Stage 7] WARNING: {$withoutPrice} line(s) referenced a product with no price "
                ."and were recorded at 0.00.\n";
        }
    }
};
