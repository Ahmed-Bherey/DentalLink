<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a role.
 *
 * Intentionally minimal. `assignable_at_registration` is an internal policy
 * flag and is not exposed; the /api/role/index endpoint already returns only
 * assignable roles, so the flag would be redundant and would leak the existence
 * of privileged roles.
 */
class RoleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
        ];
    }
}
