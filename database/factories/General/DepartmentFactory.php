<?php

namespace Database\Factories\General;

use App\Models\General\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Departments are the application's de-facto role model (users.department_id).
 *
 * NOTE (deferred to Stage 3): conflating "organisational unit" with "security
 * role" is a known architectural defect. This factory reflects the CURRENT
 * schema and does not attempt to model a future role table.
 *
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'desc' => $this->faker->sentence(),
            'code' => $this->faker->unique()->slug(1),
        ];
    }

    /**
     * The canonical departments are looked up by code, so tests must reuse the
     * same row rather than creating duplicates.
     */
    public static function ofCode(string $code): Department
    {
        return Department::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst($code), 'desc' => "Test {$code} department"]
        );
    }

    public function doctor(): static
    {
        return $this->state(fn () => ['code' => 'doctor', 'name' => 'Doctor']);
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['code' => 'supplier', 'name' => 'Supplier']);
    }
}
