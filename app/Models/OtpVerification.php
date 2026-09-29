<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per issued OTP. Only otp_hash is stored — the plain code never
 * touches the database (spec section 6).
 */
class OtpVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'mobile',
        'user_id',
        'purpose',
        'otp_hash',
        'expires_at',
        'attempts',
        'resend_count',
        'used_at',
    ];

    protected $hidden = ['otp_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
            'resend_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isConsumable(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired();
    }

    /**
     * Verification records that are neither used nor expired: the candidate
     * the verifier checks a submitted code against.
     */
    public function scopeActive(Builder $query, string $mobile, OtpPurpose $purpose): Builder
    {
        return $query->where('mobile', $mobile)
            ->where('purpose', $purpose->value)
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }
}
