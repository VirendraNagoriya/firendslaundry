<?php

namespace App\Models;

use App\Enums\PricingType;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'pricing_type',
        'rate',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'pricing_type' => PricingType::class,
            'rate' => 'decimal:2',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function isPricedByWeight(): bool
    {
        return $this->pricing_type === PricingType::KG;
    }

    /**
     * Price for a given quantity/weight, rounded to the nearest paisa.
     */
    public function priceFor(float $quantityOrWeight): float
    {
        return round((float) $this->rate * $quantityOrWeight, 2);
    }
}
