<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

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
            'name'          => 'required|string|max:255',
            'email'         => 'required|email|unique:users,email',
            'city_id'       => 'required|exists:cities,id|integer',
            'phone'         => 'required|string|unique:users,phone',
            'phone2'        => 'nullable|string',
            'address'       => 'required|string',
            // Security (Stage 1): department_id is the de-facto role. A self-registering
            // user may only claim a non-privileged department. Anything else is rejected
            // server-side, regardless of what the client sends.
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->whereIn(
                    'code',
                    config('auth.self_assignable_department_codes', [])
                ),
            ],
            'password'      => 'required|string|min:6',
            'image'         => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.required' => 'الرجاء تحديد نوع الحساب.',
            'department_id.exists'   => 'عفوا, نوع الحساب المحدد غير متاح للتسجيل.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation errors',
            'errors' => $validator->errors()
        ], 422));
    }
}
