<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRegion extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'region_id',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
