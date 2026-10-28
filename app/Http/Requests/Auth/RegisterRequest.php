<?php

namespace App\Http\Requests\Auth;

use App\Support\PasswordPolicy;
use App\Support\RoleCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'city_id' => 'required|exists:cities,id|integer',
            'phone' => 'required|string|unique:users,phone',
            'phone2' => 'nullable|string',
            'address' => 'required|string',
            // IDENTITY (Stage 3): the role is the authoritative access decision.
            // Only a role flagged `assignable_at_registration` in the database may
            // be claimed, so super_admin can never be self-assigned regardless of
            // what the client sends.
            'role_id' => [
                'required_without:department_id',
                'nullable',
                'integer',
                Rule::exists('roles', 'id')->where('assignable_at_registration', true),
            ],

            // LEGACY (deprecated): callers that still send department_id keep
            // working. The department must map to a self-assignable role by code.
            // Removal is scheduled once the frontend has migrated to role_id.
            'department_id' => [
                'required_without:role_id',
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->whereIn(
                    'code',
                    RoleCode::selfAssignable()
                ),
            ],

            // COMPATIBILITY NOTE: `confirmed` is deliberately NOT applied here.
            // The live registration form does not send `password_confirmation`,
            // and adding it would break account creation on deploy. Confirmation
            // IS enforced on password change and password reset, which have no
            // existing consumers. Add it here once the frontend sends the field.
            //
            // The strength rules DO apply, so the minimum length rises from 6 to
            // the configured policy. This affects new registrations only;
            // existing passwords are untouched.
            'password' => ['required', PasswordPolicy::rules()],
            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.required_without' => 'الرجاء تحديد نوع الحساب.',
            'role_id.exists' => 'عفوا, نوع الحساب المحدد غير متاح للتسجيل.',
            'department_id.required_without' => 'الرجاء تحديد نوع الحساب.',
            'department_id.exists' => 'عفوا, نوع الحساب المحدد غير متاح للتسجيل.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation errors',
            'errors' => $validator->errors(),
        ], 422));
    }
}
