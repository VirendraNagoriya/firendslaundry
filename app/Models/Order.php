<?php

namespace App\Models;

use App\Enums\FulfilmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use BelongsToBusiness;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'branch_id',
        'customer_id',
        'address_id',
        'order_number',
        'order_source',
        'order_status',
        'fulfilment_type',
        'notes',
        'approx_weight',
        'actual_weight',
        'pickup_charge',
        'delivery_charge',
        'subtotal',
        'discount',
        'total_amount',
        'payment_status',
        'paid_at',
        'created_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'order_source' => OrderSource::class,
            'order_status' => OrderStatus::class,
            'fulfilment_type' => FulfilmentType::class,
            'payment_status' => PaymentStatus::class,
            'approx_weight' => 'decimal:3',
            'actual_weight' => 'decimal:3',
            'pickup_charge' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'address_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function pickupAssignment(): HasOne
    {
        return $this->hasOne(PickupAssignment::class)->latestOfMany('assigned_at');
    }

    public function deliveryAssignment(): HasOne
    {
        return $this->hasOne(DeliveryAssignment::class)->latestOfMany('assigned_at');
    }

    /**
     * The full assignment history, not just the current boy. Kept so a
     * reassignment never erases who did the earlier leg, and so a
     * deactivated employee keeps their history (spec section 21).
     */
    public function pickupAssignments(): HasMany
    {
        return $this->hasMany(PickupAssignment::class);
    }

    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('order_status', [
            OrderStatus::COMPLETED->value,
            OrderStatus::CANCELLED->value,
        ]);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeStatus(Builder $query, OrderStatus $status): Builder
    {
        return $query->where('order_status', $status->value);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }

    // -----------------------------------------------------------------
    // State helpers
    // -----------------------------------------------------------------

    public function canTransitionTo(OrderStatus $target): bool
    {
        return $this->order_status->canTransitionTo($target);
    }

    public function isTerminal(): bool
    {
        return $this->order_status->isTerminal();
    }

    public function isOpen(): bool
    {
        return $this->order_status->isOpen();
    }

    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::PAID;
    }

    public function amountDue(): float
    {
        return round((float) $this->total_amount - (float) $this->amountPaid(), 2);
    }

    public function amountPaid(): float
    {
        // A voided collection no longer counts towards the balance.
        return (float) $this->payments()
            ->where('status', '!=', PaymentStatus::REFUNDED->value)
            ->sum('amount');
    }

    /**
     * A KG-priced order is not finally priced until the laundry has
     * weighed it (spec section 13).
     */
    public function hasKgServices(): bool
    {
        return $this->items->contains(
            fn (OrderItem $item) => $item->service?->isPricedByWeight()
        );
    }

    public function isAwaitingActualWeight(): bool
    {
        return $this->hasKgServices() && $this->actual_weight === null;
    }
}
