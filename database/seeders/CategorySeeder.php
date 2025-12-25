<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use App\Models\General\Category;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class CategorySeeder extends Seeder
{
    public function run()
    {
        // مستخدم (مورد أو أدمن)
        $userId = User::first()->id ?? 1;

        $categories = [
            [
                'name' => 'Dental Instruments',
                'desc' => 'Professional dental instruments used in clinics.',
                'img'  => 'categories/instruments.png',
            ],
            [
                'name' => 'Dental Materials',
                'desc' => 'Materials used for fillings, restorations, and impressions.',
                'img'  => 'categories/materials.png',
            ],
            [
                'name' => 'Orthodontics',
                'desc' => 'Orthodontic tools and accessories.',
                'img'  => 'categories/orthodontics.png',
            ],
            [
                'name' => 'Endodontics',
                'desc' => 'Root canal instruments and materials.',
                'img'  => 'categories/endodontics.png',
            ],
            [
                'name' => 'Infection Control',
                'desc' => 'Disposable and sterilization products.',
                'img'  => 'categories/infection-control.png',
            ],
            [
                'name' => 'Dental Equipment',
                'desc' => 'Dental machines and handpieces.',
                'img'  => 'categories/equipment.png',
            ],
        ];

        foreach ($categories as $category) {
            Category::create([
                'user_id' => $userId,
                'name'    => $category['name'],
                'desc'    => $category['desc'],
                'img'     => $category['img'],
            ]);
        }
    }
}