<?php

namespace App\Models;

use App\Enums\PricingType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'service_id',
        'quantity',
        'approx_weight',
        'actual_weight',
        'unit_price',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'pricing_type' => PricingType::class,
            'quantity' => 'integer',
            'approx_weight' => 'decimal:3',
            'actual_weight' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isPricedByWeight(): bool
    {
        return $this->service?->pricing_type === PricingType::KG;
    }

    /**
     * The figure actually billed for this line: the measured weight when
     * one has been recorded, otherwise the customer's estimate.
     */
    public function billableWeight(): float
    {
        return (float) ($this->actual_weight ?? $this->approx_weight ?? 0);
    }
}
