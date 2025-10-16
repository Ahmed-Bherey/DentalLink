<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AuthService
{
    /**
     * عرض بروفايل المستخدم الحالى
     */
    public function getProfile(User $user): User
    {
        return $user->load([
            'city:id,name',
            'department:id,name,code',
            'schedules' => function ($q) {
                $q->orderByRaw("FIELD(day_name,
                    'Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday')");
            }
        ]);
    }

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
        $imgPath = null;

        if (isset($data['img']) && $data['img'] instanceof \Illuminate\Http\UploadedFile) {
            $imgPath = $data['img']->store('users', 'public');
        }
        return User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'] ?? null,
            'phone2'    => $data['phone2'] ?? null,
            'address'    => $data['address'] ?? null,
            'city_id'    => $data['city_id'] ?? null,
            'department_id'    => $data['department_id'] ?? null,
            'password'       => Hash::make($data['password']),
            'img'         => $imgPath,
        ]);
    }

    // تحديث بيانات الحساب
    public function updateProfile(User $user, array $data): User
    {
        if (isset($data['img']) && $data['img'] instanceof \Illuminate\Http\UploadedFile) {
            // حذف الصورة القديمة لو وُجدت
            if ($user->img && Storage::disk('public')->exists($user->img)) {
                Storage::disk('public')->delete($user->img);
            }
            // رفع الصورة الجديدة
            $data['img'] = $data['img']->store('users', 'public');
        }

        // 1️⃣ تحديث بيانات المستخدم
        $user->update([
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'phone' => $data['phone'] ?? $user->phone,
            'address' => $data['address'] ?? $user->address,
            'img' => $data['img'] ?? $user->img,
        ]);

        // 2️⃣ تحديث المواعيد اليومية
        if (isset($data['schedule']) && is_array($data['schedule'])) {
            foreach ($data['schedule'] as $day) {
                $user->schedules()->updateOrCreate(
                    ['day_name' => $day['name']],
                    [
                        'active' => filter_var($day['active'], FILTER_VALIDATE_BOOLEAN),
                        'from' => $day['from'] ? date('H:i:s', strtotime($day['from'])) : null,
                        'to' => $day['to'] ? date('H:i:s', strtotime($day['to'])) : null,
                    ]
                );
            }
        }

        return $user->load('schedules');
    }
}
