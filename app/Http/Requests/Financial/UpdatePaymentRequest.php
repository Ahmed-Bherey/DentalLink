<?php

namespace App\Http\Requests\Financial;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdatePaymentRequest extends FormRequest
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
            // Stage 6: min prevents zero/negative; max is the ceiling of the
            // DECIMAL(12,2) column the value lands in. Without it a larger
            // number passes validation and then fails at the database, which
            // surfaces as a 500 instead of a field error.
            'paid' => 'required|numeric|min:0.01|max:9999999999.99',
            'date' => 'sometimes|required|date',
        ];
    }

    public function messages(): array
    {
        return [
            'paid.required' => 'الرجاء تحديد المبلغ.',
            'paid.numeric' => 'المبلغ يجب أن يكون رقمًا.',
            'paid.min' => 'المبلغ يجب أن يكون أكبر من 0.',
            'paid.max' => 'المبلغ أكبر من الحد المسموح به.',
            'date.required' => 'الرجاء تحديد التاريخ.',
            'date.date' => 'صيغة التاريخ غير صحيحة.',
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
