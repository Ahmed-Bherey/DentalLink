<?php

namespace App\Services\System;

use App\Models\General\City;

class CityServices
{
    /**
     * عرض جميع المدن
     */
    public function index()
    {
        return City::orderByDesc('created_at')->get();
    }

    /**
     * عرض مدينة محددة
     */
    public function show($id)
    {
        return City::findOrFail($id);
    }

    /**
     * إنشاء مدينة جديدة
     */
    public function create($user, array $data)
    {
        return City::create([
            'user_id' => $user->id,
            'name'    => $data['name'],
        ]);
    }

    /**
     * تحديث مدينة
     */
    public function update($user, $id, array $data)
    {
        $city = City::where('user_id', $user->id)->findOrFail($id);
        $city->update($data);

        return $city;
    }

    /**
     * حذف مدينة
     */
    public function delete($user, $id)
    {
        $city = City::where('user_id', $user->id)->findOrFail($id);
        $city->delete();
    }
}
