<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STAGE 5 — make mandatory ownership and relationship columns NOT NULL.
 *
 * Every foreign key in this schema was declared `->nullable()`, apparently as a
 * blanket habit rather than because any of them is optional. That matters more
 * after Stage 4 than it did before, because the authorization layer now reads
 * these columns to decide access, and it fails closed on NULL:
 *
 *     ChecksOwnership::owns()  →  a NULL owner id is owned by NOBODY
 *
 * So a row with a NULL owner is not merely untidy. It is permanently
 * unreachable: no user can view it, edit it, or delete it through the API, and
 * it will never appear in an owner-scoped listing. It is invisible, undeletable
 * data — and for `payments` or `order_items`, invisible undeletable money.
 *
 * The columns below are all set unconditionally by the application on every
 * write path, and none of them is the target of an ON DELETE SET NULL rule, so
 * NULL is not reachable through normal operation either. Requiring it closes
 * the gap between what the code guarantees and what the schema allows.
 *
 * DELIBERATELY LEFT NULLABLE (each for a specific reason):
 *
 *   users.role_id           Stage 3 defines "no role" as a valid, access-free
 *                           state and the authorization tests depend on it.
 *   users.department_id     Legacy. Registration via role_id leaves it unset.
 *   users.city_id           Target of ON DELETE SET NULL as of this stage.
 *   categories.user_id      Target of ON DELETE SET NULL as of this stage.
 *   cities.user_id          Target of ON DELETE SET NULL as of this stage.
 *   notifications_centers.user_id
 *                           Not an ownership column in the authorization sense;
 *                           no policy reads it. Left alone.
 *
 * SAFETY
 *
 * A pre-flight scan counts NULLs in every target column first. If any are found
 * the migration aborts having changed nothing, and names the table, column and
 * count. It does not invent an owner for an orphaned row — that is a data
 * decision for a human. No production database was reachable from this
 * environment, so this scan is how production data actually gets verified: at
 * deploy time, against the real rows, before anything is altered.
 */
return new class extends Migration
{
    /** @var array<string, string[]> table => columns that must be present */
    private const REQUIRED = [
        // Ownership columns read directly by Stage 4 policies.
        'products' => ['user_id'],
        'orders' => ['doctor_id'],
        'payments' => ['doctor_id', 'supplier_id'],
        'receipts' => ['user_id'],
        'carts' => ['doctor_id', 'product_id'],
        'favorite_products' => ['doctor_id', 'product_id'],
        'packages' => ['supplier_id'],

        // Relationship columns from which ownership is DERIVED. OrderItemPolicy
        // resolves the acting party through order_id and product_id; if either
        // is NULL the line item has no determinable owner at all.
        'order_items' => ['order_id', 'product_id'],
        'package_items' => ['package_id', 'product_id'],

        // Both halves identify the ledger row; the unique constraint added in
        // the previous migration is only meaningful if neither can be NULL.
        'order_expenses' => ['doctor_id', 'supplier_id'],
    ];

    public function up(): void
    {
        $this->guardAgainstNulls();

        foreach (self::REQUIRED as $table => $columns) {
            foreach ($columns as $column) {
                $this->setNullability($table, $column, nullable: false);
            }
        }
    }

    public function down(): void
    {
        foreach (self::REQUIRED as $table => $columns) {
            foreach ($columns as $column) {
                $this->setNullability($table, $column, nullable: true);
            }
        }
    }

    /**
     * Refuse to run if any target column still contains NULL.
     *
     * Reports every offending column in one pass rather than failing on the
     * first: a half-diagnosed data problem means a second failed deploy.
     *
     * @throws RuntimeException
     */
    private function guardAgainstNulls(): void
    {
        $violations = [];

        foreach (self::REQUIRED as $table => $columns) {
            foreach ($columns as $column) {
                $count = DB::table($table)->whereNull($column)->count();

                if ($count > 0) {
                    $violations[] = "    {$table}.{$column} → {$count} row(s) with NULL";
                }
            }
        }

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            "MIGRATION ABORTED — cannot require these columns while NULLs remain.\n\n"
            .implode("\n", $violations)."\n\n"
            ."Each of these columns identifies an owner or a parent record. A NULL means the row\n"
            ."cannot be attributed to anyone, which also makes it invisible to the authorization\n"
            ."layer added in Stage 4 — it can no longer be read, edited or deleted through the API.\n\n"
            ."Nothing has been modified. Decide what each orphaned row should belong to (or whether\n"
            ."it should be removed), apply that decision, then run the migration again.\n"
            .'This migration will not guess at an owner.'
        );
    }

    /**
     * Toggle NOT NULL while preserving the column's exact declared type.
     *
     * MariaDB requires a full MODIFY COLUMN, and Laravel's ->change() would pull
     * in doctrine/dbal — a dependency this project does not have and that Stage 2
     * already declined to add. Reading COLUMN_TYPE back from information_schema
     * keeps the rewrite faithful instead of hardcoding "bigint unsigned" and
     * quietly changing a column that happens to differ.
     */
    private function setNullability(string $table, string $column, bool $nullable): void
    {
        $type = DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if ($type === null) {
            throw new RuntimeException("Cannot alter {$table}.{$column}: column not found.");
        }

        $null = $nullable ? 'NULL' : 'NOT NULL';

        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$type->t} {$null}");
    }
};
