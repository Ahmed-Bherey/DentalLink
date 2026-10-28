<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STAGE 6 — store money as exact decimal, not binary floating point.
 *
 * Seven of the nine monetary columns were declared FLOAT (MariaDB widened them
 * to DOUBLE(8,2)). Binary floating point cannot represent most decimal
 * fractions exactly. That is tolerable for a value written once and read back,
 * and not tolerable for `order_expenses.total/paid/remaining`, which are a
 * running balance mutated by repeated read-modify-write: the error accumulates
 * and never cancels, so the ledger drifts away from the payments that built it.
 *
 * PRECISION AND SCALE — DECIMAL(12,2), chosen rather than assumed
 *
 * Scale 2: every existing column is already scale 2, and EGP subdivides into
 * piastres. Keeping the scale identical is what makes this conversion provably
 * lossless — no existing stored value has a third decimal place to truncate.
 *
 * Precision 12: the existing columns cap at 999,999.99 (8,2). That is ample for
 * a single product price or one payment, but `order_expenses.total` is a
 * CUMULATIVE balance between one doctor and one supplier that grows with every
 * delivered order and is only ever reduced by returns. A long-running trading
 * relationship can exceed one million EGP, at which point inserts start failing
 * or silently clamping. DECIMAL(12,2) allows up to 9,999,999,999.99, which
 * removes the ceiling without reserving absurd storage.
 *
 * WHY THIS IS SAFE ON EXISTING DATA
 *
 * Every conversion widens the range and keeps the scale:
 *
 *     DOUBLE(8,2)  → DECIMAL(12,2)     range widens, scale identical
 *     DECIMAL(8,2) → DECIMAL(12,2)     range widens, scale identical
 *
 * No value representable in the old column is unrepresentable in the new one,
 * so no rounding or truncation can occur. The pre-flight check below verifies
 * that empirically anyway, because the migration may meet a schema that earlier
 * hand-edits have left in a different shape than the migrations describe.
 *
 * NOT CHANGED: `products.price` is converted too, but nothing about pricing
 * SEMANTICS is touched here — no base price, no selling price, no snapshot.
 * Those are Stage 7. This migration only changes how a number is stored.
 */
return new class extends Migration
{
    private const PRECISION = 12;

    private const SCALE = 2;

    /** @var array<string, string[]> table => monetary columns */
    private const MONEY_COLUMNS = [
        'order_expenses' => ['total', 'paid', 'remaining'],
        'payments' => ['amount', 'requested_amount'],
        'orders' => ['price'],
        'packages' => ['price'],
        'products' => ['price'],
        'receipts' => ['value'],
    ];

    public function up(): void
    {
        $this->guardAgainstUnrepresentableValues();

        foreach (self::MONEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->convert($table, $column, 'decimal('.self::PRECISION.','.self::SCALE.')');
            }
        }
    }

    /**
     * Reverting restores DOUBLE / DECIMAL(8,2) as originally declared.
     *
     * This direction CAN lose data — a balance above 999,999.99 does not fit in
     * the old columns, and going back to DOUBLE reintroduces binary
     * representation error. It is provided so the migration is reversible in
     * development, and it refuses rather than truncating if any stored value has
     * outgrown the narrower column.
     */
    public function down(): void
    {
        $this->guardAgainstNarrowingLoss();

        foreach (self::MONEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                // orders.price and packages.price were DECIMAL(8,2) originally;
                // everything else was FLOAT, which MariaDB stores as DOUBLE(8,2).
                $original = in_array($table, ['orders', 'packages'], true)
                    ? 'decimal(8,2)'
                    : 'double(8,2)';

                $this->convert($table, $column, $original);
            }
        }
    }

    /**
     * Confirm every stored value fits the target column before altering anything.
     *
     * Given the widening described above this should never fire. It exists
     * because "should never fire" is a claim about the schema the migrations
     * produce, not about the schema actually deployed.
     *
     * @throws RuntimeException
     */
    private function guardAgainstUnrepresentableValues(): void
    {
        $ceiling = bcsub(bcpow('10', (string) (self::PRECISION - self::SCALE)), '0.01', self::SCALE);
        $violations = [];

        foreach (self::MONEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $count = DB::table($table)
                    ->whereNotNull($column)
                    ->whereRaw("ABS(`{$column}`) > ?", [$ceiling])
                    ->count();

                if ($count > 0) {
                    $violations[] = "    {$table}.{$column} → {$count} row(s) exceed {$ceiling}";
                }
            }
        }

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            'MIGRATION ABORTED — values will not fit DECIMAL('.self::PRECISION.','.self::SCALE.").\n\n"
            .implode("\n", $violations)."\n\n"
            ."Nothing has been modified. Widen the target precision or correct these rows first.\n"
            .'This migration will not truncate money.'
        );
    }

    /**
     * @throws RuntimeException
     */
    private function guardAgainstNarrowingLoss(): void
    {
        $violations = [];

        foreach (self::MONEY_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $count = DB::table($table)
                    ->whereNotNull($column)
                    ->whereRaw("ABS(`{$column}`) > ?", ['999999.99'])
                    ->count();

                if ($count > 0) {
                    $violations[] = "    {$table}.{$column} → {$count} row(s) exceed 999999.99";
                }
            }
        }

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            "ROLLBACK ABORTED — these values do not fit the original (8,2) columns:\n\n"
            .implode("\n", $violations)."\n\n"
            .'Nothing has been modified. Rolling back would silently truncate real money.'
        );
    }

    /**
     * Preserve the column's nullability and default while changing only its type.
     *
     * Laravel's ->change() would need doctrine/dbal, which this project has
     * declined to add since Stage 2. Reading the current definition back from
     * information_schema keeps NOT NULL and DEFAULT intact — rewriting them by
     * hand is how a MODIFY COLUMN quietly drops a default.
     */
    private function convert(string $table, string $column, string $type): void
    {
        $meta = DB::selectOne(
            'SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if ($meta === null) {
            throw new RuntimeException("Cannot convert {$table}.{$column}: column not found.");
        }

        $null = $meta->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL';

        // MariaDB 10.4 reports "no default" for a nullable column as the STRING
        // 'NULL' rather than SQL NULL. Quoting that produces DEFAULT 'NULL',
        // which the server rejects on a numeric column. Normalise both spellings.
        $current = $meta->COLUMN_DEFAULT;
        $hasDefault = $current !== null && strcasecmp((string) $current, 'NULL') !== 0;

        if (! $hasDefault) {
            // A nullable column with no default still needs DEFAULT NULL stated
            // explicitly, or MODIFY drops the implicit one.
            $default = $meta->IS_NULLABLE === 'YES' ? ' DEFAULT NULL' : '';
        } else {
            // These are all numeric columns; emit the literal unquoted so the
            // server stores it as a number rather than a string constant.
            $default = is_numeric($current)
                ? ' DEFAULT '.$current
                : ' DEFAULT '.DB::getPdo()->quote($current);
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$type} {$null}{$default}");
    }
};
