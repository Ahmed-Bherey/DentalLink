<?php

namespace App\Services\System;

use App\Models\General\Category;
use Illuminate\Support\Facades\Storage;

class CategoryService
{
    // عرض الكل
    public function index()
    {
        return Category::OrderBy('created_at', 'desc')->get();
    }

    // عرض بيانات تصنيف
    public function show($user, $id)
    {
        return Category::findOrFail($id);
    }

    // انشاء تصنيف جديد
    public function create($user, $data)
    {
        $imagePath = $data['img']->store('categories', 'public');
        $category = Category::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'img' => $imagePath,
            'desc' => $data['desc'],
        ]);

        return $category;
    }

    // تحديث تصنيف
    public function update($user, $id, $data)
    {
        $category = Category::where('user_id', $user->id)->findOrFail($id);

        if (isset($data['img'])) {
            // حذف الصورة القديمة إن وجدت
            if ($category->img && Storage::disk('public')->exists($category->img)) {
                Storage::disk('public')->delete($category->img);
            }
            $data['img'] = $data['img']->store('categories', 'public');
        }
        $category->update($data);

        return $category;
    }

    // حذف تصنيف
    public function delete($user, $id)
    {
        $category = Category::where('user_id', $user->id)->findOrFail($id);

        // حذف الصورة من التخزين
        if ($category->img && Storage::disk('public')->exists($category->img)) {
            Storage::disk('public')->delete($category->img);
        }

        $category->delete();
    }
}
