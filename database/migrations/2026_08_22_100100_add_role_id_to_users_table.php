<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the authoritative role link to users.
 *
 * Deliberately NULLABLE. A user whose legacy department cannot be mapped to a
 * known role must NOT be given a guessed role — see the backfill migration. The
 * application treats a null role as "no role", which the deny-by-default query
 * scopes already handle safely.
 *
 * onDelete('restrict') because deleting a role out from under live users would
 * silently strip their identity. Roles are system reference data and are not
 * expected to be deleted at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id')->nullable()->after('department_id');

            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropIndex(['role_id']);
            $table->dropColumn('role_id');
        });
    }
};
