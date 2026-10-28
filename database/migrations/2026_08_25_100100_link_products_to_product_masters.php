<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STAGE 7 — give every product row a canonical identity, and split price in two.
 *
 * `products` keeps its two remaining responsibilities and becomes explicitly
 * "one user's holding of one canonical product":
 *
 *   supplier row  =  an OFFER      (base_price = cost, price = selling price)
 *   doctor row    =  INVENTORY     (prices not meaningful; carried for display)
 *
 * WHY `price` IS NOT RENAMED
 *
 * `price` already means "what the buyer pays" everywhere — resources, the Excel
 * import/export, order totals, package composition. Renaming it to
 * `selling_price` would touch every one of those for no behavioural gain and
 * would silently break the import format. Instead `base_price` is ADDED
 * alongside, and `price` is documented as the selling price. New meaning is
 * introduced by the new column, not by redefining an existing one.
 *
 * DATA MIGRATION STRATEGY — stated plainly, because this is the risky part
 *
 * Existing rows must be mapped onto canonical products. Rows are grouped by
 * EXACT, case-insensitive, whitespace-trimmed name equality — nothing weaker.
 * There is no fuzzy matching, no similarity scoring, no category heuristic, no
 * edit-distance: two rows are the same product only if their names are literally
 * the same string.
 *
 * That is a deliberate line. Exact equality is deterministic and reproducible;
 * anything looser would be this migration guessing at product identity, and a
 * wrong guess would fuse two genuinely different products permanently.
 *
 * Even so, exact-name grouping is an ASSUMPTION: two suppliers could both sell
 * something called "Composite A" and mean different things. The blast radius is
 * deliberately limited:
 *
 *   - Each supplier keeps their OWN products row, with their OWN prices. No
 *     offer is merged into another and no price is altered.
 *   - Historical order values are unaffected: the snapshot migration that runs
 *     after this one freezes unit prices onto order_items.
 *   - The only consequence of a wrong grouping is two products sharing one
 *     catalogue entry, which a Super Admin can split later.
 *
 * The migration reports exactly how many rows were grouped, so the merge is
 * visible rather than silent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Everything that can refuse this migration is checked FIRST, against
        // the untouched table. Validating after adding the column would leave a
        // half-migrated schema behind on failure, and the retry would then die
        // on "duplicate column" instead of showing the real problem.
        $rows = $this->readAndValidateExistingProducts();

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('product_master_id')->nullable()->after('user_id');

            // The supplier's own cost. Nullable: it is genuinely unknown for
            // every pre-existing row, and inventing one would be fabricating
            // financial data. Doctor inventory rows have no base price at all.
            $table->decimal('base_price', 12, 2)->nullable()->after('price');
        });

        $this->backfillCanonicalProducts($rows);

        // Every row now has an identity, and every code path that creates a
        // product resolves one (InventoryService, the delivery path, the Excel
        // import). Required, for the same reason Stage 5 required ownership
        // columns: a holding with no canonical product is invisible to the
        // catalogue, unmatchable on delivery, and cannot be created through the
        // application — so it should not be storable either.
        DB::statement('ALTER TABLE products MODIFY product_master_id BIGINT UNSIGNED NOT NULL');

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('product_master_id')->references('id')->on('product_masters')
                ->onUpdate('cascade')->onDelete('restrict');

            // One holding per user per canonical product. This is what stops a
            // supplier listing the same product twice, and what lets the
            // delivery path find a doctor's inventory row by identity instead of
            // by name string.
            $table->unique(['user_id', 'product_master_id'], 'products_owner_master_unique');
        });

        // `price` already has a non-negative CHECK from the Stage 6 money
        // migration; only the newly added `base_price` needs one. Re-adding the
        // existing constraint fails with "duplicate CHECK constraint name".
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_base_price_non_negative CHECK (base_price IS NULL OR base_price >= 0)');
    }

    /**
     * Reverting needs one non-obvious step, the same one Stage 5 met.
     *
     * `products_owner_master_unique` starts with `user_id`, which already had a
     * single-column index backing its foreign key. MariaDB drops such an index
     * as redundant the moment a composite one covers the same leading column —
     * so applying this migration silently removed `products_user_id_foreign`.
     *
     * Dropping the composite index without restoring it leaves that foreign key
     * with nothing to stand on, and MariaDB refuses:
     *
     *     SQLSTATE[HY000] 1553: Cannot drop index … needed in a foreign key constraint
     *
     * Recreate the single-column index first, then drop the composite one.
     */
    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE products DROP CONSTRAINT `chk_products_base_price_non_negative`');
        } catch (\Throwable $e) {
            DB::statement('ALTER TABLE products DROP CHECK `chk_products_base_price_non_negative`');
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['product_master_id']);
            $table->index('user_id', 'products_user_id_foreign');
            $table->dropUnique('products_owner_master_unique');
            $table->dropColumn(['product_master_id', 'base_price']);
        });

        // The canonical rows themselves belong to the previous migration.
    }

    /**
     * The grouping key: exact name, case-insensitive, trimmed. Nothing weaker.
     *
     * No fuzzy matching, no similarity scoring, no edit distance. Two rows are
     * the same product only if their names are literally the same string.
     */
    private function groupingKey(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }

    /**
     * Read every product row and refuse the migration if it cannot be mapped.
     *
     * Performs NO writes. Both failure modes are reported against the original
     * table so a retry starts from a clean schema.
     *
     * @return \Illuminate\Support\Collection
     *
     * @throws RuntimeException
     */
    private function readAndValidateExistingProducts()
    {
        $rows = DB::table('products')
            ->select('id', 'user_id', 'name', 'category_id', 'desc', 'img')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        $unnamed = $rows->filter(fn ($r) => trim((string) $r->name) === '');

        if ($unnamed->isNotEmpty()) {
            $count = $unnamed->count();
            $ids = $unnamed->pluck('id')->take(50)->implode(', ');

            throw new RuntimeException(<<<MSG
                MIGRATION ABORTED — {$count} product row(s) have no name.

                A canonical product is identified by its name, so a nameless row cannot be mapped
                to one, and inventing an identity for it would be fabricating catalogue data.

                Affected product ids: {$ids}

                Nothing has been modified. Give these rows a name, then run the migration again.
                MSG);
        }

        $this->assertNoOwnerHoldsTheSameProductTwice($rows);

        return $rows;
    }

    /**
     * The new unique index would fail if one user already holds two rows whose
     * names group to the same canonical product. Detect it up front and explain
     * it, rather than letting index creation die with a bare duplicate-key error.
     *
     * NOT auto-resolved: collapsing two of a supplier's own listings means
     * choosing which price and which stock level survives, which is a commercial
     * decision.
     *
     * @throws RuntimeException
     */
    private function assertNoOwnerHoldsTheSameProductTwice($rows): void
    {
        $seen = [];
        $conflicts = [];

        foreach ($rows as $row) {
            $pair = $row->user_id.'|'.$this->groupingKey($row->name);

            if (isset($seen[$pair])) {
                $conflicts[$pair][] = $row->id;
            } else {
                $seen[$pair] = $row->id;
            }
        }

        if ($conflicts === []) {
            return;
        }

        $detail = collect($conflicts)->take(20)->map(function ($ids, $pair) use ($seen) {
            [$userId, $name] = explode('|', $pair, 2);

            return "    user {$userId} — \"{$name}\" appears as product ids "
                .implode(', ', array_merge([$seen[$pair]], $ids));
        })->implode('
');

        $pairs = count($conflicts);

        throw new RuntimeException(<<<MSG
            MIGRATION ABORTED — {$pairs} owner/product name pair(s) are duplicated.

            These users already hold two or more product rows whose names group to the same
            canonical product (matching is exact, case-insensitive and trimmed). Collapsing them
            means deciding which price and which stock quantity survives, which is a commercial
            decision this migration will not make on your behalf.

            {$detail}

            Nothing has been modified. Resolve the duplicates, then run the migration again.
            MSG);
    }

    /**
     * Create one canonical product per distinct name, then point every products
     * row at it.
     *
     * Only reached once readAndValidateExistingProducts() has confirmed the data
     * can be mapped, so this method does not fail partway.
     */
    private function backfillCanonicalProducts($rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $now = now();
        $canonicalIdByKey = [];
        $grouped = 0;

        foreach ($rows as $row) {
            $key = $this->groupingKey($row->name);

            if (! isset($canonicalIdByKey[$key])) {
                // The FIRST row to claim a name donates its descriptive fields.
                // Ordering by id makes that deterministic and reproducible.
                $canonicalIdByKey[$key] = DB::table('product_masters')->insertGetId([
                    'name' => trim($row->name),
                    'category_id' => $row->category_id,
                    'desc' => $row->desc,
                    'img' => $row->img,
                    'is_active' => true,
                    'created_by' => null,   // pre-existing: no known author
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $grouped++;
            }

            DB::table('products')
                ->where('id', $row->id)
                ->update(['product_master_id' => $canonicalIdByKey[$key]]);
        }

        // Surfaced rather than silent: the operator sees precisely how much
        // grouping the exact-name rule performed on their data.
        $canonical = count($canonicalIdByKey);
        echo PHP_EOL."  [Stage 7] {$rows->count()} product row(s) -> {$canonical} canonical product(s); "
            ."{$grouped} row(s) grouped onto an existing name.".PHP_EOL;
    }
};
