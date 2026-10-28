<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FULFILMENT TRACKING — the column C-1, C-2 and C-3 all need.
 *
 * WHY A MIGRATION IS UNAVOIDABLE HERE
 *
 * Fulfilment was recorded only as `orders.status = 'delivered'` — one flag for
 * the whole order. An order can contain lines from several suppliers, so a
 * single order-level flag cannot express:
 *
 *   C-1  WHICH supplier has fulfilled WHICH lines, so each can be charged for
 *        their own goods instead of the whole order total going to whoever
 *        pressed "delivered" first.
 *   C-2  Whether a line has ALREADY been fulfilled, so a repeated request can
 *        be refused instead of charging and moving stock a second time.
 *   C-3  Whether a line was ever fulfilled at all, so a return cannot reverse
 *        an obligation that was never created.
 *
 * No existing column carries this. `order_items.status` is the RETURN
 * lifecycle (confirmed / delete_pending / edit_pending) and overloading it
 * would repeat the mistake Stage 7 had to undo when it split the overloaded
 * `returned_quantity` in two. `orders.status` is order-wide by definition.
 *
 * So: one nullable timestamp per line. Null = not yet fulfilled. Additive,
 * reversible, and it makes "fulfilled" a fact about the thing that is actually
 * fulfilled — the line.
 *
 * ALSO ADDED
 *
 * - `orders.status` gains 'returned', so a fully returned order has somewhere
 *   to go other than being deleted (H-2). Same one-value enum extension Stage 6
 *   used for voided payments.
 * - `products.quantity >= 0` CHECK (H-3). The application now validates stock
 *   before fulfilling; this stops anything else — a console command, a manual
 *   query — from driving inventory negative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Null until the supplying supplier fulfils this line.
            $table->timestamp('fulfilled_at')->nullable()->after('status');

            // The hot path is "this supplier's unfulfilled lines on this order".
            $table->index(['order_id', 'fulfilled_at'], 'order_items_order_fulfilled_index');
        });

        // Existing delivered orders were fulfilled in full under the old
        // all-or-nothing rule, so their lines are marked fulfilled. Without this
        // their history would read as never-delivered and a legitimate return
        // against them would start being refused.
        $backfilled = DB::table('order_items')
            ->whereIn('order_id', DB::table('orders')->select('id')->where('status', 'delivered'))
            ->update(['fulfilled_at' => DB::raw('COALESCE(updated_at, created_at, NOW())')]);

        if ($backfilled > 0) {
            echo PHP_EOL."  [Fulfilment] Marked {$backfilled} line(s) of already-delivered orders as fulfilled.".PHP_EOL;
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'pending',
            'preparing',
            'delivered',
            'rejected',
            'delete_pending',
            'edit_pending',
            'confirmed',
            'returned'
        ) NOT NULL DEFAULT 'pending'");

        $this->addNonNegativeStockConstraint();
    }

    public function down(): void
    {
        $this->dropCheckConstraint('products', 'chk_products_quantity_non_negative');

        $returned = DB::table('orders')->where('status', 'returned')->count();

        if ($returned > 0) {
            throw new RuntimeException(
                "ROLLBACK ABORTED — {$returned} order(s) currently have status 'returned'.\n\n"
                ."Removing the enum value would coerce them to an invalid empty status.\n"
                .'Decide what those orders should become before rolling back.'
            );
        }

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM(
            'pending',
            'preparing',
            'delivered',
            'rejected',
            'delete_pending',
            'edit_pending',
            'confirmed'
        ) NOT NULL DEFAULT 'pending'");

        Schema::table('order_items', function (Blueprint $table) {
            // MariaDB drops a single-column index as redundant the moment a
            // composite one covers the same leading column. `order_id` leads
            // the index added above, so creating it silently removed
            // `order_items_order_id_foreign`. Dropping the composite without
            // restoring that leaves the foreign key with no index to stand on:
            //
            //   SQLSTATE[HY000] 1553: Cannot drop index … needed in a foreign
            //   key constraint
            //
            // Recreate it first. (Stages 5 and 7 met the same trap.)
            $table->index('order_id', 'order_items_order_id_foreign');
            $table->dropIndex('order_items_order_fulfilled_index');
            $table->dropColumn('fulfilled_at');
        });
    }

    /**
     * Drop a CHECK constraint, whatever the server calls the operation.
     *
     * MariaDB 10.4 understands `DROP CONSTRAINT` but NOT `DROP CHECK`; MySQL 8
     * learned `DROP CONSTRAINT` only in 8.0.19 and uses `DROP CHECK` before
     * that. Skipping when the constraint is absent matters too: otherwise a
     * retried rollback fails on the fallback's syntax rather than on anything
     * real.
     */
    private function dropCheckConstraint(string $table, string $constraint): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS present FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint]
        );

        if ($exists === null) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        } catch (\Throwable $e) {
            DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$constraint}`");
        }
    }

    /**
     * Stock may never be negative (H-3).
     *
     * Pre-flight REPORTS existing negatives and refuses; it does not rewrite
     * them. A negative stock level is the visible residue of a real double
     * fulfilment, and silently setting it to zero would destroy the evidence
     * and misstate the supplier's inventory. Correcting it is a stock-take
     * decision for the supplier, not something a migration should guess.
     *
     * @throws RuntimeException
     */
    private function addNonNegativeStockConstraint(): void
    {
        $offenders = DB::table('products')
            ->where('quantity', '<', 0)
            ->select('id', 'user_id', 'name', 'quantity')
            ->get();

        if ($offenders->isNotEmpty()) {
            $detail = $offenders->take(20)
                ->map(fn ($p) => "    product #{$p->id} (owner {$p->user_id}) \"{$p->name}\" = {$p->quantity}")
                ->implode("\n");

            throw new RuntimeException(
                'MIGRATION ABORTED — '.$offenders->count()." product(s) already have negative stock.\n\n"
                ."{$detail}\n\n"
                ."These are almost certainly the residue of the duplicate-fulfilment defect (C-2) or of\n"
                ."fulfilling an order that exceeded available stock (H-3), both fixed in this change.\n\n"
                ."Nothing has been modified. The safest remediation is a stock-take: have each supplier\n"
                ."confirm the true quantity on hand and set it explicitly, so the correction is recorded\n"
                ."as a deliberate adjustment. This migration will not overwrite inventory on your behalf.\n"
                .'Re-run it once the quantities are correct.'
            );
        }

        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_quantity_non_negative CHECK (quantity IS NULL OR quantity >= 0)');
    }
};
