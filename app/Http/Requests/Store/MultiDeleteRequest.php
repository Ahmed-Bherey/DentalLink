<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class MultiDeleteRequest extends FormRequest
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
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ];
    }

    public function messages()
    {
        return [
            'ids.required'   => 'يرجى تحديد المنتجات المطلوب حذفها.',
            'ids.array'      => 'يجب إرسال قائمة المنتجات كمصفوفة.',
            'ids.min'        => 'يجب تحديد منتج واحد على الأقل.',
            'ids.*.integer'  => 'معرف المنتج يجب أن يكون رقمًا صحيحًا.',
            'ids.*.exists'   => 'أحد المنتجات المحددة غير موجود.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation errors',
            'errors'  => $validator->errors()
        ], 422));
    }
}
