<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthService
{
    public function login(string $login, string $password): ?User
    {
        $user = User::where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    public function register(array $data): User
    {
        return User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'] ?? null,
            'phone2'    => $data['phone2'] ?? null,
            'address'    => $data['address'] ?? null,
            'city_id'    => $data['city_id'] ?? null,
            'department_id'    => $data['department_id'] ?? null,
            'password'       => Hash::make($data['password']),
        ]);
    }

    // تحديث بيانات الحساب
    public function updateProfile(User $user, array $data): User
    {
        // 1️⃣ تحديث بيانات المستخدم
        $user->update([
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'phone' => $data['phone'] ?? $user->phone,
            'address' => $data['address'] ?? $user->address,
        ]);

        // 2️⃣ تحديث المواعيد اليومية
        if (isset($data['schedule']) && is_array($data['schedule'])) {
            foreach ($data['schedule'] as $day) {
                $user->schedules()->updateOrCreate(
                    ['day_name' => $day['name']],
                    [
                        'active' => $day['active'],
                        'from' => $day['from'] ? date('H:i:s', strtotime($day['from'])) : null,
                        'to' => $day['to'] ? date('H:i:s', strtotime($day['to'])) : null,
                    ]
                );
            }
        }

        return $user->load('schedules');
    }
}
