<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces a first-class roles table.
 *
 * Until now `departments` doubled as the role table: every access decision in
 * the application read `$user->department->code`, and the only two rows were
 * literally 'doctor' and 'supplier'. Roles now live in their own table so that
 * identity is not entangled with organisational structure.
 *
 * The canonical rows are inserted here rather than left to a seeder, because
 * the application cannot function without them and the follow-up data migration
 * depends on them existing. RoleSeeder performs the same idempotent insert for
 * fresh installs. The literals are duplicated deliberately: a migration must not
 * depend on application classes that may change after it has run.
 */
return new class extends Migration
{
    /**
     * Kept in sync with App\Support\RoleCode::definitions().
     */
    private const ROLES = [
        ['code' => 'super_admin', 'name' => 'Super Admin', 'description' => 'Full administrative access. Assigned out-of-band only.', 'assignable_at_registration' => 0],
        ['code' => 'doctor',      'name' => 'Doctor',      'description' => 'Clinic-side buyer.',                                     'assignable_at_registration' => 1],
        ['code' => 'supplier',    'name' => 'Supplier',    'description' => 'Vendor / selling side.',                                 'assignable_at_registration' => 1],
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // Identity-level guard: only roles flagged true may ever be claimed
            // by a self-registering user. Stored in the database so no request
            // payload can influence it.
            $table->boolean('assignable_at_registration')->default(false);
            $table->timestamps();
        });

        $now = now();

        foreach (self::ROLES as $role) {
            // Idempotent: safe if the table was partially populated by a rerun.
            $exists = DB::table('roles')->where('code', $role['code'])->exists();

            if (! $exists) {
                DB::table('roles')->insert($role + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
