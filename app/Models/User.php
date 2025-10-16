<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Models\Financial\Cart;
use App\Models\Financial\Order;
use App\Models\Financial\OrderExpense;
use App\Models\Financial\Payment;
use App\Models\Financial\Receipt;
use App\Models\General\Category;
use App\Models\General\City;
use App\Models\General\Department;
use App\Models\Shopping\FavoriteProduct;
use App\Models\Store\Inventory;
use App\Models\Store\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone2',
        'address',
        'city_id',
        'password',
        'department_id',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'user_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'doctor_id');
    }

    public function doctor_orderExpenses()
    {
        return $this->hasMany(OrderExpense::class, 'doctor_id');
    }

    public function supplier_orderExpenses()
    {
        return $this->hasMany(OrderExpense::class, 'supplier_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'doctor_id');
    }

    public function categories()
    {
        return $this->hasMany(Category::class, 'user_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'user_id');
    }

    public function cities()
    {
        return $this->hasMany(City::class, 'user_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function carts()
    {
        return $this->hasMany(Cart::class, 'doctor_id');
    }

    public function favoriteProducts()
    {
        return $this->hasMany(FavoriteProduct::class, 'doctor_id');
    }

    public function schedules()
    {
        return $this->hasMany(DoctorSchedule::class, 'doctor_id');
    }
}
