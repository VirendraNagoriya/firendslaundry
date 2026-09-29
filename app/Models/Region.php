<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Region extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'status',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employee_regions')->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
