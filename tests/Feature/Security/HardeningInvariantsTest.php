<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;

/**
 * Stage 1 security regression tests for invariants that hold without a database:
 * source-level guarantees, mass-assignment configuration and shipped defaults.
 */
class HardeningInvariantsTest extends TestCase
{
    /**
     * No HTTP-reachable code may execute shell commands. The only such call was
     * OrderController::backupDatabase(), which has been removed.
     *
     * @test
     */
    public function no_shell_execution_exists_in_application_code(): void
    {
        $offenders = [];
        $pattern = '/(?<![\w\$>\-])(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/';

        foreach ($this->phpFilesIn([base_path('app'), base_path('routes')]) as $file) {
            foreach (file($file) as $i => $line) {
                $trimmed = ltrim($line);
                // Skip comment lines; we care about executable statements.
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (preg_match($pattern, $line)) {
                    $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Shell execution found in HTTP-reachable code: ' . implode(', ', $offenders));
    }

    /**
     * dd()/dump()/var_dump() in a controller leaks internal state to the client.
     *
     * @test
     */
    public function no_debug_dump_statements_exist_in_application_code(): void
    {
        $offenders = [];
        $pattern = '/(?<![\w\$>\-])(dd|dump|var_dump)\s*\(/';

        foreach ($this->phpFilesIn([base_path('app')]) as $file) {
            foreach (file($file) as $i => $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (preg_match($pattern, $line)) {
                    $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Debug dump statements found: ' . implode(', ', $offenders));
    }

    /**
     * `active` gates account activation and must never be settable from a request
     * payload. With a non-empty $fillable, omitting it blocks mass assignment.
     *
     * @test
     */
    public function user_active_flag_is_not_mass_assignable(): void
    {
        $this->assertNotContains('active', (new User)->getFillable());
        $this->assertFalse((new User)->isFillable('active'));
    }

    /**
     * Regression guard: department_id must stay fillable (AuthService::register
     * needs it) but is constrained by RegisterRequest's whitelist. If it is ever
     * removed from $fillable, every new user silently gets a NULL role, which
     * re-opens the "no department => no query filter" data-exposure bug.
     *
     * @test
     */
    public function user_department_id_remains_fillable_by_design(): void
    {
        $this->assertTrue((new User)->isFillable('department_id'));
    }

    /**
     * Financial and lifecycle fields must not be writable from arbitrary payloads
     * through the models' guarded configuration.
     *
     * @test
     */
    public function order_and_payment_lifecycle_fields_are_not_client_supplied(): void
    {
        // Order status is a server-side lifecycle value and is no longer accepted
        // by the create request.
        $rules = (new \App\Http\Requests\Financial\OrderRequest)->rules();
        $this->assertArrayNotHasKey('status', $rules, 'OrderRequest must not accept a client-supplied status.');

        // Payment creation only accepts the counterparty, amount and date.
        $paymentRules = (new \App\Http\Requests\Financial\PaymentRequest)->rules();
        $this->assertSame(['doctor_id', 'paid', 'date'], array_keys($paymentRules));
        $this->assertArrayNotHasKey('status', $paymentRules);
        $this->assertArrayNotHasKey('supplier_id', $paymentRules);
    }

    /**
     * Registration must not let a caller claim an arbitrary department (= role).
     *
     * @test
     */
    public function registration_restricts_self_assignable_departments(): void
    {
        $codes = config('auth.self_assignable_department_codes');

        $this->assertIsArray($codes);
        $this->assertNotEmpty($codes, 'The whitelist must not be empty, or registration would accept nothing.');
        $this->assertSame(['doctor', 'supplier'], $codes);

        $rules = (new \App\Http\Requests\Auth\RegisterRequest)->rules();
        $this->assertContains('required', $rules['department_id'], 'department_id must be required; a NULL department bypasses row-level filtering.');
    }

    /**
     * The shipped environment template must not default a deployment to debug
     * mode, which exposes stack traces, env vars and the Ignition routes.
     *
     * @test
     */
    public function env_example_ships_safe_defaults(): void
    {
        $env = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $env);
    }

    /**
     * Credential material must not be committable.
     *
     * @test
     */
    public function gitignore_blocks_credential_material(): void
    {
        $ignore = file_get_contents(base_path('.gitignore'));

        foreach (['/public/firebase', '/storage/app/firebase', '*-firebase-adminsdk-*.json', '*.pem'] as $pattern) {
            $this->assertStringContainsString($pattern, $ignore, "Missing .gitignore rule: {$pattern}");
        }
    }

    /**
     * No credential file may be present in the web root.
     *
     * @test
     */
    public function no_credential_files_are_served_from_the_web_root(): void
    {
        $found = [];
        foreach ($this->filesIn(base_path('public')) as $file) {
            if (preg_match('/(firebase-adminsdk|service-account)/i', basename($file))
                || preg_match('/\.(pem|p12|pfx|key)$/i', $file)) {
                $found[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame([], $found, 'Credential material in web root: ' . implode(', ', $found));
    }

    /** @return iterable<string> */
    private function phpFilesIn(array $dirs): iterable
    {
        foreach ($dirs as $dir) {
            foreach ($this->filesIn($dir) as $file) {
                if (str_ends_with($file, '.php')) {
                    yield $file;
                }
            }
        }
    }

    /** @return iterable<string> */
    private function filesIn(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }
}
