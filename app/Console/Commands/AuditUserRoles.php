<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports users whose role could not be derived automatically.
 *
 * The Stage 3 backfill mapped users.department_id -> departments.code ->
 * roles.code by exact match only. Anyone the mapping could not resolve was left
 * with role_id = NULL rather than being given a guessed access level.
 *
 * Read-only. It changes nothing; it produces the list a human must decide on
 * before users.role_id can be made NOT NULL.
 */
class AuditUserRoles extends Command
{
    protected $signature = 'identity:audit-roles';

    protected $description = 'Report users with no role, so their identity can be resolved manually';

    public function handle(): int
    {
        $total = User::count();
        $withRole = User::whereNotNull('role_id')->count();
        $withoutRole = User::whereNull('role_id')->count();

        $this->line('Users total ......... '.$total);
        $this->line('With a role ......... '.$withRole);
        $this->line('WITHOUT a role ...... '.$withoutRole);
        $this->newLine();

        if ($withoutRole === 0) {
            $this->info('Every user has a role. users.role_id can safely be made NOT NULL.');

            return self::SUCCESS;
        }

        $this->warn('These users need a manual decision. They currently have NO access.');
        $this->newLine();

        // Grouped by legacy department so the operator can see the pattern
        // without the command printing personal data.
        $rows = DB::table('users')
            ->leftJoin('departments', 'users.department_id', '=', 'departments.id')
            ->whereNull('users.role_id')
            ->selectRaw('users.department_id, departments.code as department_code, COUNT(*) as users')
            ->groupBy('users.department_id', 'departments.code')
            ->get();

        $this->table(
            ['legacy department_id', 'legacy department code', 'users'],
            $rows->map(fn ($r) => [
                $r->department_id ?? 'NULL',
                $r->department_code ?? 'NULL',
                $r->users,
            ])->all()
        );

        $this->newLine();
        $this->line('Resolve each with: php artisan identity:assign-role {user_id_or_email} {role_code}');

        // Non-zero so CI or a provisioning script can detect unresolved identities.
        return self::FAILURE;
    }
}
