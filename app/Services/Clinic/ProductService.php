<?php

namespace App\Services\Clinic;

use App\Models\Store\Product;

class ProductService
{
    // البحث
    public function search($user, $search = null, $minPrice = null, $maxPrice = null, $categoryId = null)
    {
        $query = Product::where('user_id', $user->id);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
            });
        }

        if (!is_null($minPrice)) {
            $query->where('price', '>=', $minPrice);
        }

        if (!is_null($maxPrice)) {
            $query->where('price', '<=', $maxPrice);
        }

        if (!is_null($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }
}
