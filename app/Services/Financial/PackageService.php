<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Models\Financial\Order;
use App\Models\Financial\Package;
use Illuminate\Support\Facades\DB;

class PackageService
{
    public function getAllPackages($supplier, $perPage = 10, $search = null)
    {
        return Package::with([
            'packageItems.product.category',
        ])
            ->where('supplier_id', $supplier->id)
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

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
                $package->packageItems()->create([
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

    public function show($id, $user)
    {
        return Package::where('supplier_id', $user->id)->findOrFail($id);
    }

    public function getRemainingProducts($packageId, $supplier, $perPage = 10, $search = null)
    {
        // تأكد أن الباقة تابعة لهذا المورد
        $package = Package::where('supplier_id', $supplier->id)->findOrFail($packageId);

        // جلب معرفات المنتجات الموجودة في الباقة
        $productIdsInPackage = $package->packageItems()->pluck('product_id');

        // جلب المنتجات الغير موجودة في الباقة مع دعم البحث والتصفح
        return $supplier->products()
            ->whereNotIn('id', $productIdsInPackage)
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%');
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function update(Package $package, array $data)
    {
        return DB::transaction(function () use ($package, $data) {

            $package->update([
                'name'  => $data['name'],
                'desc'  => $data['desc'],
                'price' => $data['price'],
            ]);

            if (!empty($data['products'])) {
                $package->packageItems()->delete();

                foreach ($data['products'] as $product) {
                    $package->packageItems()->create([
                        'product_id' => $product['id'],
                        'quantity'   => $product['quantity'],
                    ]);
                }
            }

            // ✅ تحميل العلاقات قبل الإرجاع
            $package->load('packageItems.product.category');

            return $package;
        });
    }

    /**
     * حذف الباقة
     */
    public function delete(Package $package): void
    {
        $package->packageItems()->delete();
        $package->delete();
    }

    /**
     * تفعيل/تعطيل الباقة
     */
    public function toggleStatus(Package $package): Package
    {
        $package->update([
            'active' => !$package->is_active,
        ]);

        return $package;
    }
}
