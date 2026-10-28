<?php

namespace App\Models;

use App\Support\RoleCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A first-class role.
 *
 * This is the AUTHORITATIVE answer to "what kind of access does this user
 * have?". It replaces the previous arrangement where `departments` was used as
 * a role table.
 *
 * Roles are system reference data. They are created by migration and by
 * RoleSeeder, never through an HTTP endpoint — there is deliberately no
 * role-management API in Stage 3.
 */
class Role extends Model
{
    use HasFactory;

    /**
     * `code` is deliberately NOT fillable: it is the identity key that every
     * access decision keys off, so it must never be settable from a payload.
     */
    protected $fillable = [
        'name',
        'description',
    ];

    protected $casts = [
        'assignable_at_registration' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    /** Roles a self-registering user is permitted to claim. */
    public function scopeSelfAssignable($query)
    {
        return $query->where('assignable_at_registration', true);
    }

    public function scopeCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function isSuperAdmin(): bool
    {
        return $this->code === RoleCode::SUPER_ADMIN;
    }

    public function isSelfAssignable(): bool
    {
        return (bool) $this->assignable_at_registration;
    }
}
