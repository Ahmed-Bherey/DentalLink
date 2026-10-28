<?php

namespace Tests\Feature\Identity;

use App\Models\General\Department;
use App\Models\Role;
use App\Models\Store\Product;
use App\Models\User;
use App\Support\RoleCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Role, Department and Ownership are three separate concepts.
 *
 * Before Stage 3 they were conflated: `departments` answered "what access does
 * this user have?", which is a role question. These tests pin the separation so
 * it cannot silently regress.
 */
class IdentitySeparationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function role_and_department_are_independent(): void
    {
        $organisational = Department::factory()->create(['code' => 'procurement', 'name' => 'Procurement']);

        $user = User::factory()->doctor()->create(['department_id' => $organisational->id]);

        // The role says what access they have...
        $this->assertSame(RoleCode::DOCTOR, $user->roleCode());
        $this->assertTrue($user->isDoctor());

        // ...the department says where they sit in the organisation. Changing one
        // must not change the other.
        $this->assertSame('procurement', $user->department->code);
        $this->assertNotSame($user->roleCode(), $user->department->code);
    }

    /** @test */
    public function a_user_can_have_a_role_but_no_department(): void
    {
        $user = User::factory()->supplier()->create(['department_id' => null]);

        $this->assertNull($user->department);
        $this->assertSame(RoleCode::SUPPLIER, $user->roleCode());
        $this->assertTrue($user->isSupplier());
    }

    /**
     * Changing the legacy department must NOT change access.
     *
     * This is the exact failure mode Stage 3 eliminates.
     *
     * @test
     */
    public function changing_the_department_does_not_change_access(): void
    {
        $user = User::factory()->doctor()->create();
        $supplierDepartment = Department::query()->where('code', RoleCode::SUPPLIER)->first()
            ?? Department::factory()->create(['code' => RoleCode::SUPPLIER, 'name' => 'Supplier']);

        $user->update(['department_id' => $supplierDepartment->id]);
        $user->refresh();

        $this->assertSame(
            RoleCode::DOCTOR,
            $user->roleCode(),
            'Pointing a user at the supplier department must not make them a supplier.'
        );
        $this->assertTrue($user->isDoctor());
        $this->assertFalse($user->isSupplier());
    }

    /** @test */
    public function ownership_is_distinct_from_both_role_and_department(): void
    {
        $supplierA = User::factory()->supplier()->create();
        $supplierB = User::factory()->supplier()->create();

        $product = Product::factory()->ownedBy($supplierA)->create();

        // Same role, same department, different ownership.
        $this->assertSame($supplierA->roleCode(), $supplierB->roleCode());
        $this->assertSame($supplierA->department_id, $supplierB->department_id);
        $this->assertSame($supplierA->id, $product->user_id);
        $this->assertNotSame($supplierB->id, $product->user_id);
    }

    /** @test */
    public function role_id_is_not_mass_assignable_on_the_user(): void
    {
        $user = $this->makeDoctor();
        $superAdminRoleId = Role::query()->where('code', RoleCode::SUPER_ADMIN)->value('id');

        $user->fill(['role_id' => $superAdminRoleId]);

        $this->assertNotSame($superAdminRoleId, $user->role_id);
        $this->assertTrue($user->isDoctor());
    }

    /** @test */
    public function the_users_table_has_exactly_one_authoritative_role_column(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('users');

        $this->assertContains('role_id', $columns);
        // department_id survives for compatibility but is not a role source.
        $this->assertContains('department_id', $columns);
        // No third, conflicting role-ish column was introduced.
        $this->assertNotContains('role', $columns);
        $this->assertNotContains('role_code', $columns);
        $this->assertNotContains('is_admin', $columns);
        $this->assertNotContains('is_super_admin', $columns);
    }

    /**
     * The legacy department table must not be dropped or emptied by Stage 3.
     *
     * @test
     */
    public function legacy_department_data_is_preserved(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('departments'));

        $department = Department::factory()->create(['code' => 'lab', 'name' => 'Lab']);
        $user = User::factory()->doctor()->create(['department_id' => $department->id]);

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'code' => 'lab']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'department_id' => $department->id]);
    }

    /**
     * No application code may make an access decision from department->code.
     *
     * @test
     */
    public function no_application_code_derives_access_from_department(): void
    {
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $i => $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                // e.g. $user->department->code == 'doctor'
                if (preg_match('/department\??->code\s*[!=]==?/', $line)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($i + 1);
                }

                // e.g. whereHas('department', fn => where('code', 'doctor'))
                if (preg_match("/whereHas\(\s*'(user\.)?department'/", $line)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Access decisions must read the role, not the department. Offenders: \n".implode("\n", $offenders)
        );
    }

    /**
     * The backfill maps legacy departments to roles by exact code match, and
     * refuses to guess for anything else.
     *
     * @test
     */
    public function the_backfill_maps_known_departments_and_leaves_unknown_ones_null(): void
    {
        $doctorDept = Department::query()->where('code', RoleCode::DOCTOR)->first()
            ?? Department::factory()->create(['code' => RoleCode::DOCTOR, 'name' => 'Doctor']);
        $unknownDept = Department::factory()->create(['code' => 'unmapped-legacy', 'name' => 'Unmapped']);

        // Simulate pre-Stage-3 rows: a department but no role.
        $mappable = User::factory()->create(['department_id' => $doctorDept->id]);
        $unmappable = User::factory()->create(['department_id' => $unknownDept->id]);
        $noDepartment = User::factory()->create(['department_id' => null]);

        DB::table('users')
            ->whereIn('id', [$mappable->id, $unmappable->id, $noDepartment->id])
            ->update(['role_id' => null]);

        // Re-run exactly what the backfill migration does.
        $roleIdsByCode = DB::table('roles')->pluck('id', 'code');
        foreach (DB::table('departments')->get() as $department) {
            if ($department->code === null || ! isset($roleIdsByCode[$department->code])) {
                continue;
            }
            DB::table('users')
                ->where('department_id', $department->id)
                ->whereNull('role_id')
                ->update(['role_id' => $roleIdsByCode[$department->code]]);
        }

        $this->assertSame(RoleCode::DOCTOR, $mappable->fresh()->roleCode(), 'An exact code match is mapped.');
        $this->assertNull($unmappable->fresh()->role_id, 'An unknown department is never guessed.');
        $this->assertNull($noDepartment->fresh()->role_id, 'A null department is never guessed.');
    }

    private function makeDoctor(): User
    {
        return User::factory()->doctor()->create();
    }
}
