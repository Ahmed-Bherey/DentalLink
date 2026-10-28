<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STAGE 5 — make deletion behaviour deliberate.
 *
 * Before this migration 23 of the 24 foreign keys in the schema were
 * ON DELETE CASCADE, applied uniformly and almost certainly by habit rather
 * than by decision. No model in this application uses SoftDeletes, so every one
 * of those cascades is an unrecoverable hard delete.
 *
 * Two of them are reachable from the running application and destroy data
 * belonging to somebody other than the person performing the delete:
 *
 *   products.user_id      A supplier deleting their own product cascades into
 *   → order_items         order_items, silently removing lines from DOCTORS'
 *                         historical orders and changing what those orders
 *                         totalled. The buyer is never consulted.
 *
 *   users.city_id         Deleting a city — a plain reference row, deletable
 *                         through CityController — CASCADED INTO USERS and
 *                         deleted every account registered in that city.
 *
 * The rule applied here, consistently:
 *
 *   RESTRICT   historical or financial fact. It must outlive its owner, and
 *              deleting the owner must fail loudly instead of erasing history.
 *   SET NULL   reference/attribute data. The child is still meaningful without
 *              the parent, so degrade the link and keep the row.
 *   CASCADE    child is part of the parent's aggregate, or is ephemeral state
 *              with no historical value. Kept deliberately, not by default.
 *
 * Reversible: down() restores the original CASCADE everywhere, so this can be
 * rolled back without data loss. Neither direction touches any row.
 */
return new class extends Migration
{
    /**
     * [table, column, parent table, new ON DELETE, original ON DELETE]
     *
     * ON UPDATE stays CASCADE throughout: primary keys are auto-increment and
     * are never rewritten, so the rule is inert either way, and changing it
     * would be churn rather than integrity.
     */
    private const CHANGES = [
        // ---- historical / financial: must survive deletion of the party ----
        // No application code deletes a user, so RESTRICT costs nothing today.
        // It exists so that a future admin feature, a console command, or a
        // manual query cannot erase a financial trail as a side effect.
        ['orders', 'doctor_id', 'users', 'restrict', 'cascade'],
        ['payments', 'doctor_id', 'users', 'restrict', 'cascade'],
        ['payments', 'supplier_id', 'users', 'restrict', 'cascade'],
        ['order_expenses', 'doctor_id', 'users', 'restrict', 'cascade'],
        ['order_expenses', 'supplier_id', 'users', 'restrict', 'cascade'],
        ['receipts', 'user_id', 'users', 'restrict', 'cascade'],
        ['products', 'user_id', 'users', 'restrict', 'cascade'],

        // The core fix: an order line is a record of what was actually bought.
        // It cannot be deleted by deleting the product it refers to.
        ['order_items', 'product_id', 'products', 'restrict', 'cascade'],

        // CategoryService already refuses to delete a category that still has
        // products ("لا يمكن حذف القسم لأنه يحتوي على منتجات"). The database was
        // configured to do the exact opposite. RESTRICT here makes the database
        // agree with the rule the application already states.
        ['products', 'category_id', 'categories', 'restrict', 'cascade'],

        // ---- reference data: keep the child, drop the link ----
        // Stage 4 treats categories and cities as globally readable reference
        // data where user_id records only who created the row. A category used
        // by many suppliers must not disappear because its creator was removed.
        // ChecksOwnership::owns() returns false for a NULL owner, so an orphaned
        // row becomes read-only rather than editable-by-anyone — it fails closed.
        ['categories', 'user_id', 'users', 'set null', 'cascade'],
        ['cities', 'user_id', 'users', 'set null', 'cascade'],

        // A user's city is an attribute of the user, not the other way round.
        // Losing a city should blank the field, never delete the account.
        ['users', 'city_id', 'cities', 'set null', 'cascade'],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as [$table, $column, $parent, $new, $_old]) {
            $this->replaceForeignKey($table, $column, $parent, $new);
        }
    }

    public function down(): void
    {
        foreach (self::CHANGES as [$table, $column, $parent, $_new, $old]) {
            $this->replaceForeignKey($table, $column, $parent, $old);
        }
    }

    /**
     * MariaDB cannot alter a foreign key in place, so drop and re-add.
     *
     * The backing index survives the drop and is reused, which keeps this cheap
     * and avoids leaving the column unindexed even momentarily.
     */
    private function replaceForeignKey(string $table, string $column, string $parent, string $onDelete): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $onDelete) {
            $blueprint->dropForeign([$column]);

            $blueprint->foreign($column)
                ->references('id')->on($parent)
                ->onUpdate('cascade')
                ->onDelete($onDelete);
        });
    }
};
