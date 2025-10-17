<?php

namespace App\Models\Financial;

use App\Models\General\NotificationsCenter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;
    protected $fillable = [
        'doctor_id',
        'supplier_id',
        'amount',
        'requested_amount',
        'date',
        'status',
    ];

    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function notificationsCenters()
    {
        return $this->morphMany(NotificationsCenter::class, 'related');
    }
}
