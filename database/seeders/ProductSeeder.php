<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Store\Product;
use Illuminate\Database\Seeder;
use App\Models\General\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductSeeder extends Seeder
{
    public function run()
    {
        // مورد (Supplier) – عدّل لو عندك طريقة مختلفة
        $supplierId = User::where('role', 'supplier')->first()->id ?? 1;

        // تصنيف
        $categoryId = Category::first()->id ?? 1;

        // أسماء منتجات حقيقية (25 منتج)
        $products = [
            'Dental Composite Resin',
            'Dental Amalgam',
            'Dental Mirror',
            'Dental Explorer Probe',
            'Dental Scaler',
            'Ultrasonic Scaler Tips',
            'Root Canal Files',
            'Gutta Percha Points',
            'Dental Cement',
            'Glass Ionomer Cement',
            'Orthodontic Brackets',
            'Orthodontic Wires',
            'Dental Syringe',
            'Disposable Dental Gloves',
            'Surgical Face Mask',
            'Dental Bibs',
            'Teeth Whitening Gel',
            'Impression Material',
            'Alginate Impression Powder',
            'Dental Polishing Discs',
            'Dental Handpiece',
            'High Speed Handpiece',
            'Low Speed Handpiece',
            'Dental Burs Set',
            'Sterilization Pouches',
        ];

        foreach ($products as $name) {
            Product::create([
                'user_id'     => $supplierId,
                'category_id' => $categoryId,
                'name'        => $name,
                'desc'        => 'High quality dental product for professional use.',
                'price'       => rand(100, 2500),
                'quantity'    => rand(20, 150),
                'img'         => 'products/default.png', // صورة ثابتة
            ]);
        }
    }
}
