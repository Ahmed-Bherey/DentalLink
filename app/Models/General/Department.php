<?php

namespace App\Models\General;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'desc',
        'code',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'department_id');
    }
}
