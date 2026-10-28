<?php

namespace App\Http\Requests\Store;

use App\Rules\ValidImageOrString;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class InventoryUpdateRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|exists:categories,id|integer',
            'img' => ['sometimes', new ValidImageOrString],
            'desc' => 'nullable|string|max:1000',
            // STAGE 7.5 (Gap #7): create and update now validate price
            // identically.
            //
            // The previous `regex:/^\d+(\.0+|\.5)?$/` allowed only whole numbers
            // or a .5 fraction, so 10.25 was ACCEPTED on create and REJECTED on
            // update — and the rule contradicted the DECIMAL(12,2) column behind
            // it, which stores any two-place value. The bounds below match
            // InventoryRequest and the column exactly.
            'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],

            // STAGE 7.5 (Gap #6): the supplier's COST is now editable, not
            // write-once at creation. Same rules as on create. Nullable so a
            // supplier can clear a cost they no longer track.
            //
            // Changing this does NOT touch any existing order: order lines carry
            // their own frozen unit_price, and base_price never fed them anyway.
            'base_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],

            'quantity' => 'sometimes|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'عفوا، التصنيف غير موجود.',
            'img.image' => 'يجب أن تكون صورة وليست ملف',
            'img.mimes' => '(jpeg,png,jpg) امتداد الصورة غير مناسب، يجب أن تكون',
            'img.max' => 'حجم الصورة يجب ألا يزيد عن 2 ميجا',
            'price.numeric' => 'السعر يجب أن يكون رقم',
            'price.min' => 'السعر يجب ألا يكون أقل من صفر.',
            'price.max' => 'السعر أكبر من الحد المسموح به.',
            'base_price.numeric' => 'سعر التكلفة يجب أن يكون رقم',
            'base_price.min' => 'سعر التكلفة يجب ألا يكون أقل من صفر.',
            'base_price.max' => 'سعر التكلفة أكبر من الحد المسموح به.',
            'quantity.integer' => 'الكمية يجب أن تكون عددًا صحيحًا',
            'quantity.min' => 'الكمية يجب أن تكون أكبر من صفر',
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
