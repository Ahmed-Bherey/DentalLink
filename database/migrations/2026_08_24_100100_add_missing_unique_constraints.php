<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STAGE 5 — enforce, in the database, three uniqueness rules the application
 * already assumes but cannot guarantee.
 *
 * Every constraint here is backed by existing application code that would
 * misbehave if a duplicate appeared. None of them is invented for tidiness:
 * candidates such as cart lines and order lines were examined and deliberately
 * REJECTED below, because the evidence does not support them.
 *
 * SAFETY
 *
 * Each constraint is preceded by a duplicate scan. If duplicates exist the
 * migration aborts with the exact offending values and changes nothing — it
 * never deletes or merges rows to force the constraint through. Resolving a
 * duplicate is a data decision for a human, not something a migration should
 * guess at, especially where money is involved.
 *
 * No production database was reachable from this environment, so these scans
 * are the mechanism by which production data actually gets checked: they run at
 * deploy time, on the real data, before the constraint is applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // order_expenses (doctor_id, supplier_id) — the running balance ledger.
        //
        // OrderService treats this pair as a primary key in all but name:
        //
        //     OrderExpense::firstOrNew(['doctor_id' => …, 'supplier_id' => …])
        //     OrderExpense::where('doctor_id', …)->where('supplier_id', …)->first()
        //
        // and then writes total/paid/remaining onto whatever it finds. A second
        // row for the same pair would split one balance in two: firstOrNew would
        // keep updating one row while the other silently retained a stale
        // remaining amount, and which one a query returned would depend on
        // insertion order. firstOrNew is also read-then-write, so two concurrent
        // requests can genuinely create the duplicate. This is the constraint
        // with real money behind it.
        // ------------------------------------------------------------------
        $this->guardAgainstDuplicates(
            'order_expenses',
            ['doctor_id', 'supplier_id'],
            'Each doctor/supplier pair must have exactly one running-balance row. '
            .'Duplicates mean a split balance and must be merged by hand before this constraint can be applied.'
        );

        Schema::table('order_expenses', function (Blueprint $table) {
            $table->unique(['doctor_id', 'supplier_id'], 'order_expenses_doctor_supplier_unique');
        });

        // ------------------------------------------------------------------
        // favorite_products (doctor_id, product_id)
        //
        // FavoriteProductService::addToFavorite already enforces this:
        //
        //     $exists = FavoriteProduct::where(...)->exists();
        //     if ($exists) { return …; }
        //     FavoriteProduct::create([...]);
        //
        // That is a check-then-insert with a gap between the two statements, so
        // two taps on "favourite" in quick succession can both pass the check.
        // The rule is already the application's stated intent; this just makes
        // it true.
        // ------------------------------------------------------------------
        $this->guardAgainstDuplicates(
            'favorite_products',
            ['doctor_id', 'product_id'],
            'A doctor cannot favourite the same product twice. '
            .'Surplus rows are safe to delete by hand, but this migration will not choose which to remove.'
        );

        Schema::table('favorite_products', function (Blueprint $table) {
            $table->unique(['doctor_id', 'product_id'], 'favorite_products_doctor_product_unique');
        });

        // ------------------------------------------------------------------
        // departments.code
        //
        // `code` is a lookup key: the Stage 3 backfill migration resolves roles
        // through `departments.code`, and application code has matched on it too.
        // Every such lookup assumes one row per code. `roles.code` — the table
        // that superseded this one — is already unique; departments never was.
        //
        // The column is nullable and stays that way. MySQL/MariaDB permit
        // multiple NULLs under a unique index, which is the behaviour we want:
        // legacy rows with no code do not block each other.
        // ------------------------------------------------------------------
        $this->guardAgainstDuplicates(
            'departments',
            ['code'],
            'Department codes are used as lookup keys and must identify one row.',
            ignoreNulls: true
        );

        Schema::table('departments', function (Blueprint $table) {
            $table->unique('code', 'departments_code_unique');
        });

        // ------------------------------------------------------------------
        // DELIBERATELY NOT ADDED
        //
        // carts (doctor_id, product_id)
        //     CartService::store() calls Cart::create() unconditionally, with no
        //     merge and no lookup, so two lines for one product are reachable
        //     today and may well be intentional. Making them unique would turn a
        //     working request into a 500 and requires a quantity-merge decision
        //     that belongs to the cart/order domain, not to schema integrity.
        //
        // order_items (order_id, product_id)
        //     OrderService loops the submitted products and creates a line per
        //     entry. Whether a repeated product should be one merged line or two
        //     is a business question about how orders are composed, and the
        //     answer changes order totals. Deferred to Stage 6/7.
        //
        // package_items (package_id, product_id)
        //     Same shape as order_items, same reasoning.
        // ------------------------------------------------------------------
    }

    /**
     * Restoring the previous state needs one non-obvious step.
     *
     * When a composite unique index is created whose LEFTMOST column already has
     * a single-column index, MariaDB drops the now-redundant single-column index
     * on the spot. Both of the composite indexes added above begin with a column
     * that carries a foreign key, so applying this migration silently removed
     * `favorite_products_doctor_id_foreign` and `order_expenses_doctor_id_foreign`.
     *
     * Dropping the composite index without putting those back leaves each
     * foreign key with no index to stand on, and MariaDB refuses:
     *
     *     SQLSTATE[HY000] 1553: Cannot drop index … needed in a foreign key constraint
     *
     * So recreate the single-column index first, then drop the composite one.
     * Order matters; the table is never left with an unindexed foreign key.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique('departments_code_unique');
        });

        Schema::table('favorite_products', function (Blueprint $table) {
            $table->index('doctor_id', 'favorite_products_doctor_id_foreign');
            $table->dropUnique('favorite_products_doctor_product_unique');
        });

        Schema::table('order_expenses', function (Blueprint $table) {
            $table->index('doctor_id', 'order_expenses_doctor_id_foreign');
            $table->dropUnique('order_expenses_doctor_supplier_unique');
        });
    }

    /**
     * Abort — without modifying anything — if the intended constraint is already
     * violated by existing rows.
     *
     * @param  string[]  $columns
     * @param  bool  $ignoreNulls  Skip rows whose key is NULL, matching the way a
     *                             SQL unique index treats them.
     *
     * @throws RuntimeException
     */
    private function guardAgainstDuplicates(string $table, array $columns, string $why, bool $ignoreNulls = false): void
    {
        $query = DB::table($table)
            ->select($columns)
            ->selectRaw('COUNT(*) AS occurrences')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1');

        foreach ($columns as $column) {
            if ($ignoreNulls) {
                $query->whereNotNull($column);
            }
        }

        $duplicates = $query->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = $duplicates
            ->take(20)
            ->map(function ($row) use ($columns) {
                $key = collect($columns)
                    ->map(fn ($c) => "{$c}=".var_export($row->{$c}, true))
                    ->implode(', ');

                return "    {$key} → {$row->occurrences} rows";
            })
            ->implode("\n");

        $more = $duplicates->count() > 20 ? "\n    … and ".($duplicates->count() - 20).' more' : '';

        throw new RuntimeException(
            "MIGRATION ABORTED — cannot add a unique constraint on {$table} (".implode(', ', $columns).").\n\n"
            ."{$why}\n\n"
            .'Existing duplicate groups ('.$duplicates->count()."):\n{$detail}{$more}\n\n"
            ."Nothing has been modified. Resolve these rows, then run the migration again.\n"
            .'This migration will not delete or merge data on your behalf.'
        );
    }
};
