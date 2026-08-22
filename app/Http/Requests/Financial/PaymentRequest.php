<?php

namespace App\Http\Requests\Financial;

use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
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
            // Security (Stage 1): `exists:users,id` alone let a supplier record a
            // payment against ANY user account, including other suppliers. The
            // counterparty on a payment must actually be a doctor.
            'doctor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn($query) => $query->whereIn(
                        'department_id',
                        DB::table('departments')->select('id')->where('code', 'doctor')
                    )
                ),
            ],
            'paid' => 'required|numeric|min:0.01',
            'date' => 'required|date',
        ];
    }

    public function messages(): array
    {
        return [
            'doctor_id.required' => 'الرجاء تحديد طبيب.',
            'doctor_id.exists' => 'عفوا, الطبيب غير موجود',
            'paid.required' => 'الرجاء تحديد المبلغ.',
            'paid.numeric' => 'المبلغ يجب أن يكون رقمًا.',
            'paid.min' => 'المبلغ يجب أن يكون أكبر من 0.',
            'date.required' => 'الرجاء تحديد التاريخ.',
            'date.date' => 'تنسيق التاريخ غير صحيح',
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
