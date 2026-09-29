<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a sensitive action (spec section 21).
 * There is deliberately no update path in the application.
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_data',
        'new_data',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_data' => 'array',
            'new_data' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): ?Model
    {
        if ($this->entity_id === null) {
            return null;
        }

        $class = '\\App\\Models\\'.$this->entity_type;

        if (! class_exists($class)) {
            return null;
        }

        return $class::withoutGlobalScopes()->find($this->entity_id);
    }

    public function describeChange(): string
    {
        $old = $this->old_data ?? [];
        $new = $this->new_data ?? [];

        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $changed = [];

        foreach ($keys as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;

            if ($before !== $after) {
                $changed[] = $key.': '.$this->render($before).' -> '.$this->render($after);
            }
        }

        return $changed === [] ? '—' : implode('; ', $changed);
    }

    private function render(mixed $value): string
    {
        if ($value === null) {
            return '∅';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
