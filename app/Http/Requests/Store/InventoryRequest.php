<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class InventoryRequest extends FormRequest
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
            'name'     => 'required|string|max:255',
            'image'      => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'description'     => 'nullable|string|max:1000',
            'price'    => ['required', 'numeric', 'regex:/^\d+(\.5)?$/', 'min:0'],
            'quantity' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'اسم المنتج مطلوب',
            'image.required'      => 'صورة المنتج مطلوبة',
            'image.image'         =>  'يجب ان تكون صورة وليست ملف',
            'image.mimes'         => '(jpeg,png,jpg) امتداد الصورة غير مناسب, يجب ان تكون',
            'image.max'           => 'حجم الصورة يجب الا يزيد عن 2 ميجا',
            'price.required'    => 'سعر المنتج مطلوب',
            'price.numeric'     => 'السعر يجب أن يكون رقم',
            'price.regex'       => 'السعر يجب أن يكون عددًا صحيحًا أو يحتوي فقط على .5',
            'quantity.required' => 'الكمية مطلوبة',
            'quantity.integer'  => 'الكمية يجب أن تكون عدد صحيح',
            'quantity.min'      => 'الكمية يجب أن تكون أكبر من صفر',
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
