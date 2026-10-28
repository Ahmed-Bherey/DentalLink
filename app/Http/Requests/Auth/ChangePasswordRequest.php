<?php

namespace App\Http\Requests\Auth;

use App\Support\PasswordPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ChangePasswordRequest extends FormRequest
{
    public function authorize()
    {
        // Route-level auth:sanctum already guarantees an authenticated caller.
        return true;
    }

    public function rules()
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                PasswordPolicy::rules(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'كلمة المرور الحالية مطلوبة.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
            'password.different' => 'كلمة المرور الجديدة يجب أن تختلف عن الحالية.',
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
