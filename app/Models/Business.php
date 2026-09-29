<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant: one client laundry business.
 *
 * This model deliberately does NOT use the BelongsToBusiness trait — a
 * business is the isolation boundary, not data inside it, so it has no
 * business_id of its own and must stay readable by the Super Admin.
 */
class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_name',
        'slug',
        'owner_name',
        'mobile',
        'branch_limit',
        'status',
        'pickup_charge',
        'delivery_charge',
        'email',
        'address',
        'working_hours_start',
        'working_hours_end',
        'notification_templates',
    ];

    protected function casts(): array
    {
        return [
            'branch_limit' => 'integer',
            'pickup_charge' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            // MySQL TIME columns come back as strings; keep them that way so
            // "08:00:00" does not become a Carbon instance at midnight.
            'working_hours_start' => 'string',
            'working_hours_end' => 'string',
            'notification_templates' => 'array',
        ];
    }

    /**
     * The short public code a person types to say which laundry they are
     * signing in to.
     */
    public function scopeWithSlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', strtoupper(trim($slug)));
    }

    /**
     * A slug derived from the trading name, trimmed to something a person
     * can read off a bill: letters and digits only, upper case.
     */
    public static function suggestSlug(string $businessName): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $businessName) ?? '');

        return substr($base !== '' ? $base : 'LAUNDRY', 0, 6);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function branchesUsed(): int
    {
        return $this->branches()->count();
    }

    public function branchesRemaining(): int
    {
        return max(0, (int) $this->branch_limit - $this->branchesUsed());
    }

    public function hasBranchCapacity(): bool
    {
        return $this->branchesUsed() < (int) $this->branch_limit;
    }

    /**
     * Owner notification templates, falling back to neutral defaults so
     * the messaging layer never renders an empty string.
     *
     * @return array<string, string>
     */
    public function notificationTemplate(string $key, array $replace = []): string
    {
        $templates = $this->notification_templates ?? [];
        $template = $templates[$key] ?? self::defaultTemplate($key);

        return strtr($template, $replace);
    }

    /**
     * @return array<string, string>
     */
    public static function defaultTemplates(): array
    {
        return [
            'otp' => 'Your laundry login OTP is :otp. It expires in :minutes minutes.',
            'order_created' => 'Your order :order_number has been created. Total :total.',
            'order_ready' => 'Order :order_number is ready for :action.',
            'order_delivered' => 'Order :order_number has been delivered. Amount due: :total.',
        ];
    }

    private static function defaultTemplate(string $key): string
    {
        return self::defaultTemplates()[$key] ?? ':message';
    }
}
