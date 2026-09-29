<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToBusiness;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'mobile',
        'status',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function defaultAddress(): ?CustomerAddress
    {
        return $this->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();
    }

    public function e164(): ?string
    {
        return PhoneNumber::tryParse((string) $this->mobile)?->e164;
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
