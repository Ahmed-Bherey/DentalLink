<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CategoryStoreRequest extends FormRequest
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
            'name' => ['required', 'string'],
            'img'  => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'desc' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم القسم مطلوب',
            'img.required'  => 'صورة القسم مطلوبة',
            'img.image'     =>  'يجب ان تكون صورة وليست ملف',
            'img.mimes'     => 'نوع الصورة غير مسموح به. الامتدادات المسموح بها: jpeg, png, jpg',
            'img.max'       => 'حجم الصورة يجب الا يزيد عن 2 ميجا',
            'desc.string' => 'الوصف يجب أن يكون نصًا',
            'desc.max'    => 'الوصف لا يجب أن يتجاوز 500 حرف',
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
