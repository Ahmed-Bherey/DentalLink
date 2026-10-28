<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;

/**
 * Creates the dedicated test database so a developer (or CI) can bootstrap the
 * test suite in one step.
 *
 * Safety: this command refuses to act on any database whose name does not look
 * like a test database, so it can never target development or production.
 */
class CreateTestDatabase extends Command
{
    protected $signature = 'db:create-test-database
                            {--name= : Override the database name (defaults to the DB_DATABASE used by phpunit.xml)}';

    protected $description = 'Create the dedicated test database used by the automated test suite';

    private const ALLOWED_SUFFIXES = ['_test', '_testing'];

    public function handle(): int
    {
        $name = $this->option('name') ?: 'denthub_testing';

        if (! $this->looksLikeTestDatabase($name)) {
            $this->error("Refusing to create [{$name}]: the name must end in ".implode(' or ', self::ALLOWED_SUFFIXES).'.');

            return self::FAILURE;
        }

        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'mysql') {
            $this->error("This command supports the mysql driver only; the default connection uses [{$config['driver']}].");

            return self::FAILURE;
        }

        try {
            // Connect WITHOUT selecting a database so we can create it.
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']),
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $charset = $config['charset'] ?? 'utf8mb4';
            $collation = $config['collation'] ?? 'utf8mb4_unicode_ci';

            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
                $name,
                $charset,
                $collation
            ));
        } catch (\Throwable $e) {
            // Deliberately does not echo the driver message, which can contain
            // connection details.
            $this->error("Could not create [{$name}]. Check that the database server is running and the configured credentials are correct.");

            return self::FAILURE;
        }

        $this->info("Test database [{$name}] is ready.");
        $this->line('Next: php artisan test');

        return self::SUCCESS;
    }

    private function looksLikeTestDatabase(string $name): bool
    {
        foreach (self::ALLOWED_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
