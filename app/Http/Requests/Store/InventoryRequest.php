<?php

namespace App\Http\Requests\Store;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

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
            // STAGE 7 — THE RULE THAT MAKES THE CATALOGUE CANONICAL.
            //
            // A supplier or doctor SELECTS an existing product; they do not
            // define a new one. Enforced here, server-side, so it holds no
            // matter what the UI does. `name` is now optional and only overrides
            // the display label on this holding.
            //
            // Only ACTIVE masters may be selected: a withdrawn product must not
            // re-enter circulation through a new listing.
            'product_master_id' => [
                'required', 'integer',
                Rule::exists('product_masters', 'id')->where('is_active', true),
            ],
            'name' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id|integer',
            'img' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'desc' => 'nullable|string|max:1000',

            // The SELLING price - what the buyer pays. Name unchanged because
            // that is what it has always meant everywhere else.
            //
            // The old `regex:/^\d+(\.5)?$/` rule allowed only whole numbers or
            // a .5 fraction, which cannot express an ordinary price like 10.25
            // and contradicts the DECIMAL(12,2) column behind it. Replaced with
            // a bound matching the column.
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],

            // The supplier's own cost. Optional: it is commercially sensitive
            // and not every supplier tracks it.
            'base_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],

            'quantity' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'product_master_id.required' => 'الرجاء اختيار المنتج من قائمة المنتجات الرئيسية.',
            'product_master_id.exists' => 'عفوا, المنتج المحدد غير متاح.',
            'category_id.exists' => 'عفوا, التصنيف غير موجود',
            'img.image' => 'يجب ان تكون صورة وليست ملف',
            'img.mimes' => '(jpeg,png,jpg) امتداد الصورة غير مناسب, يجب ان تكون',
            'img.max' => 'حجم الصورة يجب الا يزيد عن 2 ميجا',
            'price.required' => 'سعر المنتج مطلوب',
            'price.numeric' => 'السعر يجب أن يكون رقم',
            'price.max' => 'السعر أكبر من الحد المسموح به.',
            'base_price.numeric' => 'سعر التكلفة يجب أن يكون رقم',
            'quantity.required' => 'الكمية مطلوبة',
            'quantity.integer' => 'الكمية يجب أن تكون عدد صحيح',
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
