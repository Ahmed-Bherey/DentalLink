<?php

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class PaymentRequest extends FormRequest
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'doctor_id' => 'required|exists:users,id|integer',
            'amount' => 'required|numeric|min:0.01',
        ];
    }

    public function messages(): array
    {
        return [
            'doctor_id.required' => 'الرجاء تحديد طبيب.',
            'doctor_id.exists' => 'عفوا, الطبيب غير موجود',
            'amount.required' => 'الرجاء تحديد المبلغ.',
            'amount.numeric' => 'المبلغ يجب أن يكون رقمًا.',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من 0.',
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
