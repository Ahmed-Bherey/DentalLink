<?php

namespace App\Models\General;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationsCenter extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'color',
        'related_id',
        'related_type',
        'is_read'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function related()
    {
        return $this->morphTo();
    }
}
