<?php

namespace App\Http\Requests\Financial;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ReceiptStoreRequest extends FormRequest
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
            'receipts' => 'required|array|min:1',
            'receipts.*.name' => 'required|string|max:255',
            'receipts.*.price' => 'required|numeric',
            'receipts.*.date' => 'required|date',
            'receipts.*.img' => 'nullable|image|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'receipts.required' => 'يجب إرسال فاتورة واحدة على الأقل.',
            'receipts.array' => 'البيانات يجب أن تكون في شكل مصفوفة.',

            'receipts.*.name.required' => 'الرجاء ادخال اسم الفاتورة.',
            'receipts.*.name.string' => 'اسم الفاتورة يجب ان يكون نص',
            'receipts.*.price.required' => 'قيمة الفاتورة مطلوبة',
            'receipts.*.price.numeric' => 'مبلغ الفاتورة يجب أن يكون رقمًا.',
            'receipts.*.date.required' => 'تاريخ الفاتورة مطلوب',
            'receipts.*.date.date' => 'تنسيق التايخ غير صحيح',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('receipts')) {
            $receipts = $this->input('receipts');

            foreach ($receipts as $i => $receipt) {
                if (isset($receipt['date'])) {
                    try {
                        // نحول التاريخ من JS Date لصيغة Y-m-d
                        $receipts[$i]['date'] = Carbon::parse($receipt['date'])->format('Y-m-d');
                    } catch (\Exception $e) {
                        // لو Carbon معرفش يـparseه، يفضل زي ما هو علشان يضرب في ال validation
                    }
                }
            }

            // نرجع المصفوفة بعد التعديل
            $this->merge(['receipts' => $receipts]);
        }
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation errors',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
