<?php

namespace App\Console\Commands;

use App\Support\DatabaseSafety;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Print the database a command run with these flags would ACTUALLY open.
 *
 * Read-only. Exists because the 2026-08-24 data loss came from believing
 * `--env=testing` implied a test database. This makes that belief cheap to
 * check: run it with the same flags you are about to use destructively.
 */
class ShowDatabaseTarget extends Command
{
    protected $signature = 'db:target {--database= : Inspect a specific connection instead of the default}';

    protected $description = 'Show the resolved database target and whether destructive commands are permitted against it';

    public function handle(): int
    {
        $connection = $this->option('database') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if ($config === null) {
            $this->error("No such connection: [{$connection}].");

            return self::FAILURE;
        }

        $database = (string) ($config['database'] ?? '');
        $disposable = DatabaseSafety::isDisposableDatabase($database);

        $this->newLine();
        $this->line('  <options=bold>Resolved database target</>');
        $this->newLine();

        $this->table(['', ''], [
            ['APP_ENV', app()->environment()],
            ['Connection', $connection],
            ['Driver', $config['driver'] ?? '?'],
            ['Host', $config['host'] ?? 'n/a'],
            ['Database', $database === '' ? '<unresolved>' : $database],
        ]);

        // Prove the name is not just configured but actually reachable, so a
        // typo that silently creates a new empty schema is visible here.
        try {
            $live = DB::connection($connection)->selectOne('SELECT DATABASE() AS d')->d;
            $this->line('  Live connection reports: <options=bold>'.($live ?? 'null').'</>');
        } catch (\Throwable $e) {
            $this->line('  Live connection: <fg=yellow>unreachable</> ('.class_basename($e).')');
        }

        $this->newLine();

        if ($disposable) {
            $this->info('  SAFE — this is a disposable test database.');
            $this->line('  Permitted here: '.implode(', ', DatabaseSafety::DESTRUCTIVE_COMMANDS));
        } else {
            $this->warn('  PROTECTED — this is NOT a test database.');
            $this->line('  Refused here: '.implode(', ', DatabaseSafety::DESTRUCTIVE_COMMANDS));
            $this->line('  A test database name must end in "'.implode('" or "', DatabaseSafety::TEST_DB_SUFFIXES).'".');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
