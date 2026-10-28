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
use App\Models\Store\Product;
use App\Notifications\ResetPasswordNotification;
use App\Support\RoleCode;
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
        'img',
        // LEGACY (Stage 3): `department_id` is retained for frontend
        // compatibility only. It is no longer the source of any access decision
        // — `role_id` is. Scheduled for removal once consumers have migrated.
        'department_id',
        // `role_id` and `active` are deliberately NOT fillable. Both are
        // identity/privilege fields and must only be set through the explicit
        // paths in AuthService (registration) or the console commands. With a
        // non-empty $fillable, omitting them blocks every mass-assignment path.
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

    /**
     * Use the API-oriented reset notification instead of Laravel's default,
     * which links to a server-rendered route this application does not have.
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * The AUTHORITATIVE role relationship.
     *
     * Every identity decision reads from here. Never from department().
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * LEGACY organisational link, retained for frontend compatibility.
     *
     * Before Stage 3 this table doubled as the role table. It is no longer
     * consulted for any access decision. See ROLE_MODEL.md.
     */
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    // ------------------------------------------------------------- identity

    /**
     * A user with no role has NO access. Null is never treated as a wildcard.
     */
    public function roleCode(): ?string
    {
        return $this->role?->code;
    }

    public function hasRole(string ...$codes): bool
    {
        $current = $this->roleCode();

        return $current !== null && in_array($current, $codes, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleCode::SUPER_ADMIN);
    }

    public function isDoctor(): bool
    {
        return $this->hasRole(RoleCode::DOCTOR);
    }

    public function isSupplier(): bool
    {
        return $this->hasRole(RoleCode::SUPPLIER);
    }

    /**
     * Restrict a user query to one or more role codes.
     *
     * Replaces the previous whereHas('department', code = ...) pattern.
     */
    public function scopeWithRole($query, string ...$codes)
    {
        return $query->whereHas('role', fn ($q) => $q->whereIn('code', $codes));
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
