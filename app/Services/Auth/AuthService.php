<?php

namespace App\Services\Auth;

use App\Models\General\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * عرض بروفايل المستخدم الحالى
     */
    public function getProfile(User $user): User
    {
        return $user->load([
            'city:id,name',
            'role:id,code,name',
            // LEGACY: still eager-loaded so the deprecated `department` block in
            // UserResource keeps working during the compatibility period.
            'department:id,name,code',
            'schedules' => function ($q) {
                $q->orderByRaw("FIELD(day_name,
                    'Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday')");
            },
        ]);
    }

    public function login(string $login, string $password): ?User
    {
        $user = User::where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Registers a new account.
     *
     * IDENTITY RULE: the role is resolved server-side from a role that is
     * flagged `assignable_at_registration` in the database. It is never taken
     * from the payload as-is, and `role_id` is not mass-assignable — it is set
     * explicitly below. A privileged role therefore cannot be self-claimed even
     * if a caller invents a field name.
     *
     * LEGACY: callers may still send `department_id` instead of `role_id`. The
     * legacy department is resolved to the matching role by code and is also
     * persisted so existing frontend reads keep working. See ROLE_MODEL.md.
     */
    public function register(array $data): User
    {
        $imgPath = null;

        if (isset($data['img']) && $data['img'] instanceof \Illuminate\Http\UploadedFile) {
            $imgPath = $data['img']->store('users', 'public');
        }

        $role = $this->resolveSelfAssignableRole($data);

        $user = new User;

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'phone2' => $data['phone2'] ?? null,
            'address' => $data['address'] ?? null,
            'city_id' => $data['city_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'password' => Hash::make($data['password']),
            'img' => $imgPath,
        ]);

        // Not fillable by design.
        $user->role_id = $role->id;

        $user->save();

        return $user;
    }

    /**
     * Resolves the role a registration request is allowed to receive.
     *
     * Accepts either the new `role_id` or the legacy `department_id`. In both
     * cases the resulting role must be self-assignable; anything else is a
     * server-side rejection, not a silent downgrade.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function resolveSelfAssignableRole(array $data): Role
    {
        $role = null;

        if (! empty($data['role_id'])) {
            $role = Role::selfAssignable()->find($data['role_id']);
        } elseif (! empty($data['department_id'])) {
            $department = Department::find($data['department_id']);

            if ($department !== null) {
                $role = Role::selfAssignable()->code((string) $department->code)->first();
            }
        }

        if ($role === null) {
            // Mirrors the FormRequest rules; this is the last line of defence if
            // registration is ever invoked from somewhere other than HTTP.
            throw ValidationException::withMessages([
                'role_id' => 'عفوا, نوع الحساب المحدد غير متاح للتسجيل.',
            ]);
        }

        return $role;
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
