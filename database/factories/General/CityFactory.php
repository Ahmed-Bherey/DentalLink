<?php

namespace Database\Factories\General;

use App\Models\General\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        return [
            // cities.user_id is the creator. It is nullable and left null by
            // default so a city does not implicitly create a user (which would
            // recurse, because users.city_id points back at cities).
            'user_id' => null,
            'name' => $this->faker->unique()->city(),
        ];
    }
}
