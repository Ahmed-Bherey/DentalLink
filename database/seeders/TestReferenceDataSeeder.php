<?php

namespace Database\Seeders;

use App\Models\General\City;
use App\Models\General\Department;
use Illuminate\Database\Seeder;

/**
 * Deterministic reference data for tests and local manual verification.
 *
 * Departments are the application's role model and are looked up by `code`, so
 * they must exist before most flows work. This seeder creates only the two
 * self-assignable departments plus one city.
 *
 * It deliberately does NOT create users, products, orders or any credential —
 * tests build those with factories so each test owns its own data.
 *
 * Usage:  php artisan db:seed --class=TestReferenceDataSeeder
 */
class TestReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'doctor',   'name' => 'Doctor',   'desc' => 'Clinic-side buyer'],
            ['code' => 'supplier', 'name' => 'Supplier', 'desc' => 'Vendor'],
        ] as $department) {
            Department::firstOrCreate(
                ['code' => $department['code']],
                ['name' => $department['name'], 'desc' => $department['desc']]
            );
        }

        City::firstOrCreate(['name' => 'Test City']);
    }
}
