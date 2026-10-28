<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills users.role_id from the legacy departments-as-roles arrangement.
 *
 * MAPPING RULE — the only rule applied:
 *
 *     users.department_id -> departments.code -> roles.code (exact match)
 *
 * A user is updated ONLY when their department's code matches a known role code
 * exactly. This is a lossless, mechanical mapping, not an interpretation.
 *
 * DELIBERATELY NOT MAPPED:
 *   - users with department_id IS NULL
 *   - users whose department code does not match any role code
 *
 * Those keep role_id = NULL. Assigning them a role would be a guess about a
 * real person's access level, and this migration will not guess. Run
 *
 *     php artisan identity:audit-roles
 *
 * to list them for manual review. Enforcing NOT NULL on users.role_id is
 * deliberately deferred until that list is empty on the target environment.
 *
 * REVERSIBILITY: down() nulls role_id only. It never touches department_id, so
 * the legacy source of truth survives a rollback intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: on a fresh install these tables may be empty, which is fine.
        if (! DB::getSchemaBuilder()->hasTable('departments')) {
            return;
        }

        $roleIdsByCode = DB::table('roles')->pluck('id', 'code');

        foreach (DB::table('departments')->get() as $department) {
            $code = $department->code;

            if ($code === null || ! isset($roleIdsByCode[$code])) {
                // Unknown department code -> no mapping, no guess.
                continue;
            }

            DB::table('users')
                ->where('department_id', $department->id)
                ->whereNull('role_id')
                ->update(['role_id' => $roleIdsByCode[$code]]);
        }
    }

    public function down(): void
    {
        DB::table('users')->update(['role_id' => null]);
    }
};
