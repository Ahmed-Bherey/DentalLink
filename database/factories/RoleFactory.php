<?php

namespace Database\Factories;

use App\Models\Role;
use App\Support\RoleCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->slug(2),
            'name' => $this->faker->unique()->words(2, true),
            'description' => $this->faker->sentence(),
            'assignable_at_registration' => false,
        ];
    }

    /**
     * Fetch (or create) one of the canonical roles.
     *
     * Roles are reference data keyed by a unique `code`, so tests must reuse the
     * existing row rather than creating a second one.
     */
    public static function ofCode(string $code): Role
    {
        $existing = Role::query()->where('code', $code)->first();

        if ($existing !== null) {
            return $existing;
        }

        $definition = collect(RoleCode::definitions())->firstWhere('code', $code)
            ?? ['code' => $code, 'name' => $code, 'description' => null, 'assignable_at_registration' => false];

        $role = new Role;
        // `code` and `assignable_at_registration` are not fillable by design.
        $role->code = $definition['code'];
        $role->name = $definition['name'];
        $role->description = $definition['description'];
        $role->assignable_at_registration = $definition['assignable_at_registration'];
        $role->save();

        return $role;
    }

    public function selfAssignable(): static
    {
        return $this->state(fn () => ['assignable_at_registration' => true]);
    }

    /**
     * `code` is not fillable, so states cannot set it; this helper does.
     */
    public function withCode(string $code): static
    {
        return $this->afterMaking(function (Role $role) use ($code) {
            $role->code = $code;
        })->afterCreating(function (Role $role) use ($code) {
            $role->code = $code;
            $role->save();
        });
    }
}
