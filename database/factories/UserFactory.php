<?php

namespace Database\Factories;

use App\Models\General\City;
use App\Models\General\Department;
use App\Models\User;
use App\Support\RoleCode;
use Database\Factories\General\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            // users.phone is NOT NULL UNIQUE. The stock Laravel factory omitted it,
            // which made it unusable against this schema.
            'phone' => $this->uniquePhone(),
            'phone2' => null,
            'address' => $this->faker->streetAddress(),
            'city_id' => City::factory(),
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'remember_token' => Str::random(10),
            'img' => null,
            'department_id' => null,
        ];
    }

    /**
     * A user in the "doctor" department (the buying side).
     */
    public function doctor(): static
    {
        return $this->withRole(RoleCode::DOCTOR);
    }

    /**
     * A user in the "supplier" department (the selling side).
     */
    public function supplier(): static
    {
        return $this->withRole(RoleCode::SUPPLIER);
    }

    public function superAdmin(): static
    {
        // No legacy department exists for super_admin; that is the point.
        return $this->afterCreating(function (User $user) {
            $user->role_id = RoleFactory::ofCode(RoleCode::SUPER_ADMIN)->id;
            $user->save();
        });
    }

    /**
     * Assigns the AUTHORITATIVE role, and mirrors the legacy department when a
     * matching one exists so compatibility paths stay exercised.
     *
     * role_id is not fillable by design, so it is set after creation.
     */
    public function withRole(string $code): static
    {
        return $this->state(function () use ($code) {
            $department = Department::query()->where('code', $code)->first();

            return ['department_id' => $department?->id
                ?? DepartmentFactory::ofCode($code)->id];
        })->afterCreating(function (User $user) use ($code) {
            $user->role_id = RoleFactory::ofCode($code)->id;
            $user->save();
        });
    }

    /**
     * A user with NO department.
     *
     * This state exists specifically to test the known "role filters have no
     * else-branch" exposure. Do not use it as a general-purpose user.
     */
    public function withoutDepartment(): static
    {
        return $this->state(fn () => ['department_id' => null]);
    }

    /**
     * A user with NO role. Exercises deny-by-default: such a user must have no
     * access at all, never implicit wildcard access.
     */
    public function withoutRole(): static
    {
        return $this->state(fn () => ['department_id' => null])
            ->afterCreating(function (User $user) {
                $user->role_id = null;
                $user->save();
            });
    }

    /**
     * users.active is intentionally NOT mass-assignable (Stage 1 hardening), so
     * it has to be forced after the row exists.
     */
    public function inactive(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->forceFill(['active' => 0])->save();
        });
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    private function uniquePhone(): string
    {
        return '01'.$this->faker->unique()->numerify('#########');
    }
}
