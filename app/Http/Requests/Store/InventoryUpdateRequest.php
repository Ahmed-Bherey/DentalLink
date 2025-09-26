<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
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
            'name'        => 'required|string|max:255',
            'category_id' => 'sometimes|exists:categories,id|integer',
            'img'         => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'desc'        => 'nullable|string|max:1000',
            'price'       => ['required', 'numeric', 'regex:/^\d+(\.5)?$/', 'min:0'],
            'quantity'    => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'اسم المنتج مطلوب',
            'category_id.exists' => 'عفوا، التصنيف غير موجود.',
            'img.image'          => 'يجب أن تكون صورة وليست ملف',
            'img.mimes'          => '(jpeg,png,jpg) امتداد الصورة غير مناسب، يجب أن تكون',
            'img.max'            => 'حجم الصورة يجب ألا يزيد عن 2 ميجا',
            'price.required'     => 'سعر المنتج مطلوب',
            'price.numeric'      => 'السعر يجب أن يكون رقم',
            'price.regex'        => 'السعر يجب أن يكون عددًا صحيحًا أو يحتوي فقط على .5',
            'quantity.required'  => 'الكمية مطلوبة',
            'quantity.integer'   => 'الكمية يجب أن تكون عددًا صحيحًا',
            'quantity.min'       => 'الكمية يجب أن تكون أكبر من صفر',
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
