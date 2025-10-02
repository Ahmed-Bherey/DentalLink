<?php

namespace App\Models\Financial;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Receipt extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'name',
        'value',
        'img',
        'date',
    ];
    protected $casts = ['date' => 'date'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
