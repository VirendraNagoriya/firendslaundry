<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickupAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'pickup_boy_id',
        'assigned_by',
        'assigned_at',
        'picked_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'assigned_at' => 'datetime',
            'picked_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function pickupBoy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pickup_boy_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
