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

    /**
     * Update an already-resolved, already-authorized cart line.
     *
     * Authorization moved to CartPolicy::update in Stage 4. Keeping the owner
     * filter here as well would put the same rule in two places.
     */
    public function update(Cart $cart, $data)
    {
        $cart->update([
            'quantity' => $data['quantity'],
        ]);

        return $cart;
    }

    /**
     * Delete an already-resolved, already-authorized cart line.
     *
     * Authorization lives in CartPolicy::delete.
     */
    public function destroy(Cart $cart)
    {
        $cart->delete();

        return true;
    }
}
