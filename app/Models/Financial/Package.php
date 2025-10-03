<?php

namespace App\Models\Financial;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Package extends Model
{
    use HasFactory;
    protected $fillable = [
        'supplier_id',
        'name',
        'desc',
        'price',
        'active'
    ];

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function packageItems()
    {
        return $this->hasMany(PackageItem::class, 'package_id');
    }
}
