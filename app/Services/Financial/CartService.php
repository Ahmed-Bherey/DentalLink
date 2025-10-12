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
            'quantity' => $data['quantity'],
        ]);

        return $cart;
    }

    // تحديث عنصر في السلة
    public function update($id, $data)
    {
        $cart = Cart::findOrfail($id);

        $cart->update([
            'product_id' => $data['product_id'],
            'quantity'   => $data['quantity'],
        ]);

        return $cart;
    }

    // حذف عنصر من السلة
    public function destroy($id)
    {
        $cart = Cart::findOrfail($id);

        $cart->delete();

        return true;
    }
}
