<?php

namespace App\Services\Store;

use App\Models\Store\Product;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function getAll($user)
    {
        return Product::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            $imgPath = null;
            if (!empty($data['img'])) {
                $img = $data['img'];
                $imgPath = $img->store('products', 'public');
            }

            $product = Product::create([
                'user_id'    => auth()->id(),
                'name'  => $data['name'],
                'img'  => $imgPath,
                'desc'  => $data['desc'],
                'price' => $data['price'],
                'quantity'   => $data['quantity'],
            ]);

            return [
                'product'   => $product,
            ];
        });
    }

    public function getAllSuppliersProducts()
    {
        return Product::whereHas('user.department', function ($q) {
            $q->where('code', '!=', 'doctor');
        })
            ->latest()
            ->get();
    }
}
