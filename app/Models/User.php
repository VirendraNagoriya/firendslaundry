<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToBusiness;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use BelongsToBusiness;
    use HasFactory;

    protected $fillable = [
        'business_id',
        'branch_id',
        'name',
        'mobile',
        'email',
        'avatar',
        'role',
        'status',
        'last_login_at',
    ];

    /**
     * `password` is deliberately not fillable: a plaintext password must
     * never reach this model through mass assignment. Set it explicitly, and
     * the "hashed" cast below takes care of the hashing.
     */

    /**
     * "Remember me" is intentionally not supported: a laundry workstation
     * should not hold a session open indefinitely. Without these overrides
     * the framework's auth layer reads a remember_token column that the
     * users table does not have.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // No-op: nothing to persist.
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            // Hashed on assignment, so a caller can pass a plaintext value
            // and still never persist it.
            'password' => 'hashed',
            'role' => Role::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * External accounts linked to this profile, e.g. Google sign-in.
     */
    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OAuthAccount::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Whether this profile signs in with a password it owns, rather than
     * only through an external provider.
     */
    public function usesPassword(): bool
    {
        return filled($this->password);
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'employee_regions', 'employee_id', 'region_id')->withTimestamps();
    }

    public function pickupAssignments(): HasMany
    {
        return $this->hasMany(PickupAssignment::class, 'pickup_boy_id');
    }

    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class, 'delivery_boy_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SUPER_ADMIN;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::ADMIN;
    }

    public function isBranch(): bool
    {
        return $this->role === Role::BRANCH;
    }

    public function isPickupBoy(): bool
    {
        return $this->role === Role::PICKUP_BOY;
    }

    public function isDeliveryBoy(): bool
    {
        return $this->role === Role::DELIVERY_BOY;
    }

    public function isCustomer(): bool
    {
        return $this->role === Role::CUSTOMER;
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function hasOneOfRoles(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * The mobile in E.164 form, or null when it cannot be normalised.
     */
    public function e164(): ?string
    {
        return PhoneNumber::tryParse((string) $this->mobile)?->e164;
    }

    /**
     * Digits-only mobile for tel: links and CSV exports.
     */
    public function mobileDigits(): string
    {
        return preg_replace('/\D/', '', (string) $this->mobile) ?? '';
    }
}
