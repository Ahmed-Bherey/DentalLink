<?php

namespace Tests;

use App\Support\DatabaseSafety;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Runs after the application is booted but BEFORE RefreshDatabase (or any
     * other database trait) opens a connection, which is the only point where an
     * abort is still guaranteed to be non-destructive.
     */
    protected function setUpTraits()
    {
        $this->guardTestDatabase();

        return parent::setUpTraits();
    }

    /**
     * Fail loudly rather than run destructive operations against a real database.
     *
     * The "is this database disposable?" rule itself lives in
     * App\Support\DatabaseSafety, which also guards destructive artisan commands.
     * Keeping one definition matters: before Stage 5 this method carried its own
     * copy of the rule and the artisan path had no rule at all, so the suffix
     * check looked like project-wide protection when it only covered test runs.
     */
    protected function guardTestDatabase(): void
    {
        $environment = app()->environment();

        if ($environment !== 'testing') {
            $this->fail(
                "Refusing to run tests: APP_ENV is [{$environment}], expected [testing]. "
                .'Check the <php> block in phpunit.xml.'
            );
        }

        $connection = config('database.default');

        try {
            DatabaseSafety::assertDisposable(
                config("database.connections.{$connection}.database"),
                'the test suite'
            );
        } catch (RuntimeException $e) {
            $this->fail($e->getMessage());
        }
    }
}
