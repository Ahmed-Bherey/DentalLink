<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\RoleResource;
use App\Models\Role;
use App\Traits\ApiResponse;

/**
 * Read-only role listing for the registration form.
 *
 * There is deliberately NO create/update/delete endpoint. Roles are system
 * reference data created by migration and seeder; exposing management over HTTP
 * would recreate the privilege-escalation primitive that Stage 1 removed when
 * it deleted POST /api/department/store.
 */
class RoleController extends Controller
{
    use ApiResponse;

    /**
     * Roles a visitor may choose at registration.
     *
     * Returns ONLY self-assignable roles, so privileged role ids and codes are
     * never disclosed to an unauthenticated caller.
     */
    public function index()
    {
        $roles = Role::selfAssignable()->orderBy('id')->get();

        return $this->successResponse(RoleResource::collection($roles), 200);
    }
}
