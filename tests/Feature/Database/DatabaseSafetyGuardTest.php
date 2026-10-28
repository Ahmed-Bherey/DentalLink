<?php

namespace Tests\Feature\Database;

use App\Support\DatabaseSafety;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression tests for the destructive-command guard.
 *
 * These exist because of a real incident: on 2026-08-24
 * `php artisan migrate:fresh --env=testing --force` dropped every table in the
 * local development database. `.env.testing` did not exist, so `--env=testing`
 * set APP_ENV without changing DB_DATABASE.
 *
 * The invariant under test is deliberately narrow and behavioural: a destructive
 * command must be judged by the database name it will really open, and anything
 * not provably disposable must be refused.
 */
class DatabaseSafetyGuardTest extends TestCase
{
    // ------------------------------------------------------ disposability rule

    /** @test */
    public function it_accepts_database_names_that_end_in_a_test_suffix()
    {
        $this->assertTrue(DatabaseSafety::isDisposableDatabase('denthub_testing'));
        $this->assertTrue(DatabaseSafety::isDisposableDatabase('denthub_test'));
        $this->assertTrue(DatabaseSafety::isDisposableDatabase('anything_test'));
        $this->assertTrue(DatabaseSafety::isDisposableDatabase(':memory:'));
    }

    /** @test */
    public function it_rejects_the_development_database_by_name()
    {
        $this->assertFalse(DatabaseSafety::isDisposableDatabase('dentalLink'));
        $this->assertFalse(DatabaseSafety::isDisposableDatabase('denthub'));
        $this->assertFalse(DatabaseSafety::isDisposableDatabase('denthub_production'));
    }

    /**
     * The suffix must anchor at the end. A substring match would wave through
     * `testing_denthub_live`, which is exactly the kind of near-miss that makes
     * a guard feel safe while not being safe.
     *
     * @test
     */
    public function it_requires_the_test_marker_to_be_a_suffix_not_a_substring()
    {
        $this->assertFalse(DatabaseSafety::isDisposableDatabase('testing_denthub_live'));
        $this->assertFalse(DatabaseSafety::isDisposableDatabase('test_denthub'));
        $this->assertFalse(DatabaseSafety::isDisposableDatabase('denthub_test_archive'));
    }

    /** @test */
    public function it_fails_closed_when_the_database_name_is_missing_or_empty()
    {
        $this->assertFalse(DatabaseSafety::isDisposableDatabase(null));
        $this->assertFalse(DatabaseSafety::isDisposableDatabase(''));
        $this->assertFalse(DatabaseSafety::isDisposableDatabase('   '));
    }

    // ---------------------------------------------------- which commands apply

    /** @test */
    public function it_treats_the_data_destroying_commands_as_destructive()
    {
        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'] as $command) {
            $this->assertTrue(
                DatabaseSafety::isDestructiveCommand($command),
                "[{$command}] must be guarded."
            );
        }
    }

    /**
     * `migrate` and `migrate:rollback` are ordinary deploy operations. Guarding
     * them would push developers to work around the guard entirely.
     *
     * @test
     */
    public function it_leaves_non_destructive_commands_alone()
    {
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'db:seed', 'route:list', null] as $command) {
            $this->assertFalse(
                DatabaseSafety::isDestructiveCommand($command),
                '['.($command ?? 'null').'] must not be guarded.'
            );
        }
    }

    // ------------------------------------------------------------ guardCommand

    /** @test */
    public function it_blocks_a_destructive_command_pointed_at_a_non_test_database()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/REFUSED/');

        DatabaseSafety::guardCommand('migrate:fresh', 'mysql', fn () => 'dentalLink');
    }

    /** @test */
    public function it_allows_a_destructive_command_pointed_at_a_test_database()
    {
        DatabaseSafety::guardCommand('migrate:fresh', 'mysql', fn () => 'denthub_testing');

        $this->assertTrue(true, 'No exception means the command was permitted.');
    }

    /**
     * If we cannot tell which connection the command will open, we cannot tell
     * whether it is safe — so we refuse.
     *
     * @test
     */
    public function it_blocks_a_destructive_command_when_the_connection_cannot_be_resolved()
    {
        $this->expectException(RuntimeException::class);

        DatabaseSafety::guardCommand('migrate:fresh', '', fn () => 'denthub_testing');
    }

    /** @test */
    public function it_blocks_a_destructive_command_when_the_database_name_cannot_be_resolved()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unresolved/');

        DatabaseSafety::guardCommand('migrate:fresh', 'mysql', fn () => null);
    }

    /** @test */
    public function it_lets_an_unguarded_command_through_without_consulting_the_database()
    {
        DatabaseSafety::guardCommand('route:list', 'mysql', function () {
            $this->fail('A non-destructive command must not be database-checked at all.');
        });

        $this->assertTrue(true);
    }

    // ------------------------------------------------- wired into real artisan

    /**
     * The unit tests above prove the rule. This proves the rule is actually
     * ATTACHED to artisan — the gap that caused the incident was never a wrong
     * rule, it was a correct rule on a path nothing travelled through.
     *
     * @test
     */
    public function the_guard_is_wired_into_artisan_and_blocks_a_real_destructive_command()
    {
        config(['database.connections.mysql_not_a_test_db' => array_merge(
            config('database.connections.mysql'),
            ['database' => 'dentalLink']
        )]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/REFUSED.*dentalLink/s');

        // Never reaches the migrator: CommandStarting fires before handle().
        Artisan::call('migrate:fresh', ['--database' => 'mysql_not_a_test_db', '--force' => true]);
    }

    /**
     * The suite itself is running destructively (RefreshDatabase) right now, so
     * the fact that these tests execute at all is evidence the guard permits the
     * real test database. Assert it explicitly rather than leaving it implied.
     *
     * @test
     */
    public function the_suite_is_running_against_a_database_the_guard_considers_disposable()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        $this->assertTrue(
            DatabaseSafety::isDisposableDatabase($database),
            "The test suite is pointed at [{$database}], which the guard does not consider disposable."
        );
    }
}
