<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STAGE 6 — refuse negative money at the database level.
 *
 * The application now prevents overpayment and derives balances rather than
 * adjusting them, so negative money should be unreachable. These constraints
 * exist for everything that is not the application: a console command, a
 * migration, a manual query, or a future code path written by someone who has
 * not read LedgerService.
 *
 * SERVER SUPPORT — checked, not assumed
 *
 * CHECK constraints are ENFORCED on MariaDB >= 10.2.1 and MySQL >= 8.0.16.
 * MySQL 5.7 and earlier PARSE AND SILENTLY IGNORE them, which is worse than not
 * having them: the schema would advertise a guarantee that does not exist. This
 * migration therefore verifies enforcement on a temporary table before adding
 * anything, and refuses to proceed on a server that ignores the syntax rather
 * than installing decorative constraints.
 *
 * Verified enforcing on both targets used by this project: MariaDB 10.4.32
 * (local/development) and MySQL 8.0 (CI).
 *
 * WHAT IS DELIBERATELY *NOT* CONSTRAINED
 *
 *   paid <= total                and
 *   remaining = total - paid
 *
 * Both are true in normal operation, and both are asserted by the Stage 6 tests
 * — but neither can be a CHECK, because a RETURN can legitimately reduce `total`
 * below what has already been paid, leaving the doctor in credit. Constraining
 * it would make a valid return fail with a database error. Whether credit should
 * exist as a concept, and how it should be represented, is an open business
 * decision recorded in the Stage 6 report; this migration does not pre-empt it.
 */
return new class extends Migration
{
    /** @var array<string, string[]> table => columns that may never be negative */
    private const NON_NEGATIVE = [
        'payments' => ['amount', 'requested_amount'],
        'order_expenses' => ['total', 'paid', 'remaining'],
        'receipts' => ['value'],
        'products' => ['price'],
        'orders' => ['price'],
        'packages' => ['price'],
    ];

    public function up(): void
    {
        $this->assertServerEnforcesCheckConstraints();
        $this->guardAgainstExistingNegatives();

        foreach (self::NON_NEGATIVE as $table => $columns) {
            foreach ($columns as $column) {
                $name = $this->constraintName($table, $column);

                // A NULL column value makes the predicate UNKNOWN, which passes.
                // That is the behaviour we want: these columns are nullable and
                // "not set" is not the same as "negative".
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK (`{$column}` >= 0)");
            }
        }
    }

    public function down(): void
    {
        foreach (self::NON_NEGATIVE as $table => $columns) {
            foreach ($columns as $column) {
                $name = $this->constraintName($table, $column);

                // MariaDB spells this DROP CONSTRAINT; MySQL 8 spells it DROP
                // CHECK (and only learned DROP CONSTRAINT in 8.0.19). Try the
                // portable spelling first and fall back rather than branching on
                // a version string, which is easy to get subtly wrong.
                try {
                    DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
                } catch (\Throwable $e) {
                    DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$name}`");
                }
            }
        }
    }

    private function constraintName(string $table, string $column): string
    {
        return "chk_{$table}_{$column}_non_negative";
    }

    /**
     * Prove the server actually rejects a violating row before relying on it.
     *
     * @throws RuntimeException
     */
    private function assertServerEnforcesCheckConstraints(): void
    {
        DB::statement('DROP TABLE IF EXISTS _stage6_check_probe');
        DB::statement('CREATE TABLE _stage6_check_probe (v DECIMAL(12,2), CONSTRAINT _probe CHECK (v >= 0))');

        $enforced = false;

        try {
            DB::statement('INSERT INTO _stage6_check_probe (v) VALUES (-1)');
        } catch (\Throwable $e) {
            $enforced = true;
        } finally {
            DB::statement('DROP TABLE IF EXISTS _stage6_check_probe');
        }

        if (! $enforced) {
            throw new RuntimeException(
                "MIGRATION ABORTED — this database server accepts CHECK syntax but does not enforce it.\n\n"
                ."Adding these constraints would advertise a guarantee the server will not keep, which is\n"
                ."more dangerous than having no constraint at all.\n\n"
                ."CHECK constraints require MariaDB >= 10.2.1 or MySQL >= 8.0.16.\n"
                .'Server reports: '.DB::selectOne('SELECT VERSION() AS v')->v
            );
        }
    }

    /**
     * @throws RuntimeException
     */
    private function guardAgainstExistingNegatives(): void
    {
        $violations = [];

        foreach (self::NON_NEGATIVE as $table => $columns) {
            foreach ($columns as $column) {
                $count = DB::table($table)->where($column, '<', 0)->count();

                if ($count > 0) {
                    $violations[] = "    {$table}.{$column} → {$count} negative row(s)";
                }
            }
        }

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            "MIGRATION ABORTED — negative money already exists:\n\n"
            .implode("\n", $violations)."\n\n"
            ."These are almost certainly the result of the balance defects fixed in Stage 6\n"
            ."(the overpayment guard that never fired, and remaining = total + paid).\n\n"
            ."Nothing has been modified. Each row needs a human decision about what the correct\n"
            .'balance is; this migration will not overwrite money.'
        );
    }
};
