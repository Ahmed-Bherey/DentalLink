<?php

namespace App\Services\System;

use App\Models\General\Department;

class DepartmentService
{
    /**
     * Departments offered to unauthenticated callers (the registration form).
     *
     * Security (Stage 1): this endpoint is public, so it must not enumerate
     * privileged department codes. It returns only the departments a user is
     * actually allowed to self-assign at registration, which keeps it in step
     * with RegisterRequest's whitelist.
     */
    public function getAll()
    {
        return Department::whereIn(
            'code',
            config('auth.self_assignable_department_codes', [])
        )->orderBy('created_at', 'desc')->get();
    }

    // إنشاء قسم جديد
    public function create(array $data): Department
    {
        return Department::create([
            'name' => $data['name'],
            'desc' => $data['desc'] ?? null,
            'code' => $data['code'],
        ]);
    }
}
