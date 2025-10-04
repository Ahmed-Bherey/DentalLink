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

    // Accessor لحساب total_price للشهر
    protected $appends = ['total_price'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getTotalPriceAttribute()
    {
        return (float) self::where('user_id', $this->user_id)
            ->whereYear('date', $this->date->year)
            ->whereMonth('date', $this->date->month)
            ->sum('value');
    }
}
