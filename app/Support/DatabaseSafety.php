<?php

namespace App\Support;

use RuntimeException;

/**
 * Fail-closed gate for destructive database commands.
 *
 * WHY THIS EXISTS
 *
 * On 2026-08-24 `php artisan migrate:fresh --env=testing --force` dropped every
 * table in the local development database. Three protections were assumed to be
 * in force and none of them applied:
 *
 *   1. `--env=testing` looks for `.env.testing`. That file did not exist, so
 *      Laravel fell back to `.env` — APP_ENV became "testing" while DB_DATABASE
 *      stayed on the development database.
 *   2. The `DB_DATABASE` override in phpunit.xml is read by PHPUnit only. An
 *      artisan command never sees it.
 *   3. TestCase::guardTestDatabase() runs inside a PHPUnit test case. An artisan
 *      command does not pass through it.
 *
 * The common flaw is that all three trusted APP_ENV or a config file, and none
 * checked the one thing that actually matters: the name of the database the
 * connection will really open. This class checks exactly that, and nothing else.
 *
 * DESIGN RULES
 *
 * - Judge the RESOLVED database name, never APP_ENV and never the env file.
 * - Fail CLOSED. Unknown, unresolvable or ambiguous input is a refusal, not a
 *   pass. There is deliberately no "force" flag and no env override — an escape
 *   hatch here would recreate the hole this class exists to close.
 * - Be a pure function of its inputs so it can be unit-tested without touching
 *   a real connection.
 */
class DatabaseSafety
{
    /**
     * A database is treated as disposable only if its name ends in one of these.
     *
     * Deliberately a suffix match rather than a substring match: `denthub_testing`
     * qualifies, `testing_denthub_live` does not.
     */
    public const TEST_DB_SUFFIXES = ['_test', '_testing'];

    /**
     * Artisan commands that drop or truncate existing data.
     *
     * `migrate` and `migrate:rollback` are intentionally absent. Both are normal
     * deploy operations against real databases; blocking them would make this
     * guard something developers route around, which is worse than not having it.
     */
    public const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    public static function isDestructiveCommand(?string $command): bool
    {
        return $command !== null && in_array($command, self::DESTRUCTIVE_COMMANDS, true);
    }

    /**
     * Is this database name unambiguously disposable?
     *
     * In-memory SQLite has no persistent form at all, so it is always safe. Every
     * other name must opt in by suffix.
     */
    public static function isDisposableDatabase(?string $database): bool
    {
        $database = trim((string) $database);

        if ($database === '') {
            return false;   // unresolvable => not disposable
        }

        if ($database === ':memory:') {
            return true;
        }

        foreach (self::TEST_DB_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($database), $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Throw unless $database is unambiguously disposable.
     *
     * @param  string|null  $command  Command name, used only to make the message useful.
     *
     * @throws RuntimeException
     */
    public static function assertDisposable(?string $database, ?string $command = null): void
    {
        if (self::isDisposableDatabase($database)) {
            return;
        }

        throw new RuntimeException(self::refusalMessage($database, $command));
    }

    /**
     * Guard an artisan command against the connection it is really about to use.
     *
     * Non-destructive commands pass straight through. Destructive ones are
     * checked against the resolved database name for the connection that command
     * will actually open — honouring an explicit `--database=` option, because
     * that is what Laravel itself will honour.
     *
     * @param  callable(string):?string  $databaseResolver  connection name => database name
     *
     * @throws RuntimeException
     */
    public static function guardCommand(?string $command, ?string $connection, callable $databaseResolver): void
    {
        if (! self::isDestructiveCommand($command)) {
            return;
        }

        $connection = trim((string) $connection);

        if ($connection === '') {
            // We cannot tell which connection this will open, so we cannot tell
            // whether it is safe. Fail closed.
            throw new RuntimeException(self::refusalMessage(null, $command));
        }

        self::assertDisposable($databaseResolver($connection), $command);
    }

    private static function refusalMessage(?string $database, ?string $command): string
    {
        $shown = trim((string) $database) === '' ? '<unresolved>' : $database;
        $what = $command === null ? 'This destructive operation' : "[{$command}]";
        $suffixes = '"'.implode('" or "', self::TEST_DB_SUFFIXES).'"';

        return <<<MSG
            REFUSED: {$what} would run against database [{$shown}], which is not a test database.

            Destructive commands are only permitted against a database whose name ends in {$suffixes}
            (or in-memory SQLite). This is a fail-closed guard — an unresolved or ambiguous database
            name is also refused.

            If you meant to target the test database, run the command with `--env=testing` AND make
            sure `.env.testing` exists (copy `.env.testing.example`). Without that file Laravel falls
            back to `.env` and keeps your development database — which is exactly how the local
            database was destroyed on 2026-08-24.

            To verify what a command will actually target before running it:
                php artisan db:target --env=testing
            MSG;
    }
}
