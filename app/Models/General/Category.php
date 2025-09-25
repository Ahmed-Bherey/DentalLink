<?php

namespace App\Models\General;

use App\Models\Store\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'name',
        'img',
        'desc',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function getImgAttribute($value)
    {
        return $value ? asset('storage/' . $value) : null;
    }
}
