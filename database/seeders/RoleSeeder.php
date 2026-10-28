<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\RoleCode;
use Illuminate\Database\Seeder;

/**
 * Seeds the canonical roles.
 *
 * Idempotent and deterministic: safe to run any number of times. Keyed on
 * `code`, which is unique, so it can never create duplicates. Existing rows
 * have their display fields refreshed but their id is preserved, so user
 * assignments are never disturbed.
 *
 * The same rows are also created by the create_roles_table migration, because
 * the application cannot boot usefully without them. This seeder exists for
 * fresh installs and for repairing a partially-populated table.
 *
 * Seeds NO users and NO credentials.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleCode::definitions() as $definition) {
            $role = Role::query()->where('code', $definition['code'])->first() ?? new Role;

            // `code` and `assignable_at_registration` are intentionally not
            // fillable (they are identity fields), so they are set explicitly
            // rather than mass-assigned.
            $role->code = $definition['code'];
            $role->name = $definition['name'];
            $role->description = $definition['description'];
            $role->assignable_at_registration = $definition['assignable_at_registration'];
            $role->save();
        }
    }
}
