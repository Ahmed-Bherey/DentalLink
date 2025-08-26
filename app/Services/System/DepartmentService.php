<?php

namespace App\Services\System;

use App\Models\General\Department;

class DepartmentService
{
    public function getAll()
    {
        return Department::OrderBy('created_at', 'desc')->get();
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
