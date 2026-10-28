<?php

namespace App\Http\Requests\Financial;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class OrderRequest extends FormRequest
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
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            // Security (Stage 1): `status` is NOT accepted from the client on create.
            // Order status is a server-side lifecycle value; a new order is always
            // created as 'pending' by the database default. OrderService::store()
            // already ignored this input, so removing it is behaviour-preserving and
            // removes a latent mass-assignment path onto Order::$fillable['status'].
            'payment_method' => 'nullable|in:كاش,الكترونى,مدفوعات',
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
