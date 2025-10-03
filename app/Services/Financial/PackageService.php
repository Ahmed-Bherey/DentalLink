<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Models\Financial\Order;
use App\Models\Financial\Package;
use Illuminate\Support\Facades\DB;

class PackageService
{
    // انشاء عرض من المورد
    public function createPackage($supplier, $data)
    {
        return DB::transaction(function () use ($supplier, $data) {
            $package = Package::create([
                'supplier_id' => $supplier->id,
                'name'        => $data['name'],
                'desc'        => $data['desc'],
                'price'       => $data['price'],
            ]);

            // إضافة المنتجات للباقة
            foreach ($data['products'] as $productData) {
                $package->items()->create([
                    'product_id' => $productData['id'],
                    'quantity'   => $productData['quantity'],
                ]);
            }

            return $package;
        });
    }

    // الطبيب يشترى العرض
    public function buyPackage(User $doctor, Package $package, array $data)
    {
        // إنشاء الطلب
        $order = Order::create([
            'doctor_id'      => $doctor->id,
            'notes'          => $data['notes'],
            'payment_method' => $data['payment_method'],
        ]);

        // إدخال منتجات الباقة في order_items
        foreach ($package->packageItems as $item) {
            $order->orderItems()->create([
                'product_id' => $item->product_id,
                'quantity'   => $item->quantity,
            ]);
        }

        return $order;
    }
}
