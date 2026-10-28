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
    /** Update an already-resolved, already-authorized category (CategoryPolicy::update). */
    public function update(Category $category, $data)
    {
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
    /** Delete an already-resolved, already-authorized category (CategoryPolicy::delete). */
    public function delete(Category $category)
    {
        if ($category->products()->exists()) {
            throw new \Exception('لا يمكن حذف القسم لأنه يحتوي على منتجات');
        }

        // حذف الصورة من التخزين
        if ($category->img && Storage::disk('public')->exists($category->img)) {
            Storage::disk('public')->delete($category->img);
        }

        $category->delete();
    }
}
