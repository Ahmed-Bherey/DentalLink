<?php

namespace App\Http\Requests\Catalogue;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProductMasterStoreRequest extends FormRequest
{
    /**
     * Authorization is the controller's job via ProductMasterPolicy (Stage 4
     * put every access decision in a policy). Returning true here does not
     * open anything up.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Unique because the whole point of a canonical catalogue is that
            // one product has one entry. Backed by a DB unique index, so a
            // concurrent duplicate fails rather than slipping through the gap
            // between this check and the insert.
            'name' => 'required|string|max:255|unique:product_masters,name',
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
            'name.required' => 'الرجاء إدخال اسم المنتج.',
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
