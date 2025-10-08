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
}
