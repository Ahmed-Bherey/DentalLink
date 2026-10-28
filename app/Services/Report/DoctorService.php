<?php

namespace App\Services\Report;

use App\Models\User;
use App\Support\RoleCode;

class DoctorService
{
    public function getAllsuppliers($user, $perPage = 10)
    {
        $search = request()->get('search');
        $interactedOnly = request()->boolean('interacted_only');

        $query = User::withRole(RoleCode::SUPPLIER)
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            })
            ->when($interactedOnly, function ($q) use ($user) {
                $q->whereHas('supplier_orderExpenses', function ($orderQuery) use ($user) {
                    $orderQuery->where('doctor_id', $user->id);
                });
            })
            ->orderByDesc('created_at');

        return $query->paginate($perPage);
    }
}
