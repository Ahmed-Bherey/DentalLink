<?php

namespace App\Services\Financial;

use App\Models\Financial\Cart;

class CartService
{
    // عرض السلة
    public function index($doctor, $perPage = 10)
    {
        $cart = Cart::where('doctor_id', $doctor->id)
            ->paginate($perPage);
        return $cart;
    }

    // اضافة السلة
    public function store($doctor, $data)
    {
        $cart = Cart::create([
            'doctor_id' => $doctor->id,
            'product_id' => $data['product_id'],
            'quantity' => $data['quantity'] ?? null,
        ]);

        return $cart;
    }

    // تحديث عنصر في السلة
    // Security (Stage 1): owner-scoped. Previously any authenticated user could
    // change the quantity on another user's cart line by id.
    public function update($doctor, $id, $data)
    {
        $cart = Cart::where('doctor_id', $doctor->id)->findOrFail($id);

        $cart->update([
            'quantity'   => $data['quantity'],
        ]);

        return $cart;
    }

    // حذف عنصر من السلة
    // Security (Stage 1): owner-scoped. Previously any authenticated user could
    // delete another user's cart line by id.
    public function destroy($doctor, $id)
    {
        $cart = Cart::where('doctor_id', $doctor->id)->findOrFail($id);

        $cart->delete();

        return true;
    }
}
