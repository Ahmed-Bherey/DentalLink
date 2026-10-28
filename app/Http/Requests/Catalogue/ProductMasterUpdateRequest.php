<?php

namespace App\Http\Requests\Catalogue;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ProductMasterUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Ignores the row being edited, so re-saving without changing the
            // name does not collide with itself.
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('product_masters', 'name')->ignore($this->route('product_master')),
            ],
            'category_id' => 'nullable|integer|exists:categories,id',
            'desc' => 'nullable|string',
            'unit' => 'nullable|string|max:50',
            'img' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'يوجد منتج رئيسي بنفس الاسم بالفعل.',
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
