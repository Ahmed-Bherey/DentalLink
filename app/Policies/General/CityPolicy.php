<?php

namespace App\Policies\General;

use App\Models\General\City;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/**
 * Ownership: cities.user_id (the creator).
 *
 * Cities are global reference data; the index is public. Only the creator may
 * modify or delete one.
 *
 * WARNING (documented for Stage 5, NOT changed here): users.city_id has
 * ON DELETE CASCADE, so deleting a city still cascade-deletes every user in it.
 * Authorization narrows WHO can trigger that, but the cascade itself is a
 * database-integrity defect and its removal belongs to Stage 5.
 */
class CityPolicy
{
    use ChecksOwnership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, City $city): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, City $city): Response
    {
        return $this->owns($user, $city, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }

    public function delete(User $user, City $city): Response
    {
        return $this->owns($user, $city, 'user_id')
            ? Response::allow()
            : $this->denyAsMissing();
    }
}
