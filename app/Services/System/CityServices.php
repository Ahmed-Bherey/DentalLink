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
            'name' => $data['name'],
        ]);
    }

    /**
     * تحديث مدينة
     */
    /** Update an already-resolved, already-authorized city (CityPolicy::update). */
    public function update(City $city, array $data)
    {
        $city->update($data);

        return $city;
    }

    /**
     * حذف مدينة
     */
    /** Delete an already-resolved, already-authorized city (CityPolicy::delete). */
    public function delete(City $city)
    {
        $city->delete();
    }
}
