<?php

namespace App\Http\Requests\Financial;

use App\Support\RoleCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            //
            // IDENTITY (Stage 4): resolved through `roles`, not `departments`.
            // Stage 3 made `role_id` the authoritative identity column and left
            // `department_id` nullable for legacy callers, so a doctor who
            // registered with `role_id` has no department and was being rejected
            // here. The predicate is unchanged — "this user is a doctor" — only
            // the column it reads from.
            'doctor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->whereIn(
                        'role_id',
                        DB::table('roles')->select('id')->where('code', RoleCode::DOCTOR)
                    )
                ),
            ],
            // Stage 6: min prevents zero/negative; max is the ceiling of the
            // DECIMAL(12,2) column the value lands in. Without it a larger
            // number passes validation and then fails at the database, which
            // surfaces as a 500 instead of a field error.
            'paid' => 'required|numeric|min:0.01|max:9999999999.99',
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
            'paid.max' => 'المبلغ أكبر من الحد المسموح به.',
            'date.required' => 'الرجاء تحديد التاريخ.',
            'date.date' => 'تنسيق التاريخ غير صحيح',
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
