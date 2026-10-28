<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\RoleCode;
use Illuminate\Console\Command;

/**
 * Assigns a role to a user from the console.
 *
 * TEMPORARY BOUNDARY (Stage 3): this is deliberately the ONLY way to grant a
 * privileged role such as super_admin. There is no HTTP endpoint for role
 * assignment, because building one before Stage 4's policy layer exists would
 * recreate exactly the privilege-escalation primitive Stage 1 removed.
 *
 * Requiring shell access to the server is the minimum sufficient control until
 * a properly authorized admin API arrives in Stage 4.
 */
class AssignUserRole extends Command
{
    protected $signature = 'identity:assign-role
                            {user : User id or email address}
                            {role : Role code, e.g. super_admin, doctor, supplier}
                            {--force : Skip the confirmation prompt (for scripted provisioning)}';

    protected $description = 'Assign a role to a user (console-only; the sole path to a privileged role)';

    public function handle(): int
    {
        $identifier = (string) $this->argument('user');
        $roleCode = (string) $this->argument('role');

        if (! RoleCode::isValid($roleCode)) {
            $this->error("Unknown role [{$roleCode}]. Known roles: ".implode(', ', RoleCode::all()));

            return self::FAILURE;
        }

        $user = is_numeric($identifier)
            ? User::find((int) $identifier)
            : User::where('email', $identifier)->first();

        if ($user === null) {
            $this->error('No matching user found.');

            return self::FAILURE;
        }

        $role = Role::query()->where('code', $roleCode)->first();

        if ($role === null) {
            $this->error("Role [{$roleCode}] is not present in the database. Run: php artisan db:seed --class=RoleSeeder");

            return self::FAILURE;
        }

        $current = $user->roleCode() ?? 'none';

        $this->line("User #{$user->id}");
        $this->line("  current role: {$current}");
        $this->line("  new role:     {$role->code}");

        if (in_array($role->code, RoleCode::privileged(), true)) {
            $this->warn('This is a PRIVILEGED role.');
        }

        if (! $this->option('force') && ! $this->confirm('Apply this change?', false)) {
            $this->line('Aborted. Nothing was changed.');

            return self::SUCCESS;
        }

        // role_id is not fillable by design, so assign it explicitly.
        $user->role_id = $role->id;
        $user->save();

        $this->info("Role [{$role->code}] assigned to user #{$user->id}.");

        return self::SUCCESS;
    }
}
