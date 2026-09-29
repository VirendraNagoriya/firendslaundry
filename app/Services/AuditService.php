<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Records sensitive actions (spec section 21).
 *
 * Logging must never be able to break the operation it is describing, so
 * any failure to write an audit row is swallowed and reported to the
 * error log instead of rolling back the user's work.
 */
class AuditService
{
    /**
     * Attributes that must never reach the audit trail.
     */
    private const REDACTED = [
        'otp_hash',
        'password',
        'remember_token',
        'api_key',
        'token',
    ];

    public function log(
        string $action,
        Model $entity,
        ?array $oldData = null,
        ?array $newData = null,
        ?int $businessId = null,
    ): ?AuditLog {
        try {
            $user = Auth::user();

            return AuditLog::create([
                'business_id' => $businessId ?? $this->businessIdFor($entity),
                'user_id' => $user?->getKey(),
                'action' => $action,
                'entity_type' => class_basename($entity),
                'entity_id' => $entity->getKey(),
                'old_data' => $oldData === null ? null : $this->sanitise($oldData),
                'new_data' => $newData === null ? null : $this->sanitise($newData),
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit log write failed', [
                'action' => $action,
                'entity' => class_basename($entity).'#'.$entity->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a creation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function created(Model $entity, array $attributes = []): ?AuditLog
    {
        return $this->log('created', $entity, null, $attributes);
    }

    /**
     * Record an update by diffing only the attributes that actually moved.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updated(Model $entity, array $changes, array $original = []): ?AuditLog
    {
        $old = [];
        $new = [];

        foreach ($changes as $key => $value) {
            $old[$key] = $original[$key] ?? $entity->getOriginal($key);
            $new[$key] = $value;
        }

        return $this->log('updated', $entity, $old, $new);
    }

    public function deleted(Model $entity): ?AuditLog
    {
        return $this->log('deleted', $entity, $entity->getAttributes(), null);
    }

    /**
     * Record an action that has no single owning entity, such as a
     * Super Admin changing a client's branch limit.
     */
    public function event(
        string $action,
        string $entityType,
        ?int $entityId,
        array $oldData = [],
        array $newData = [],
        ?int $businessId = null,
    ): ?AuditLog {
        try {
            return AuditLog::create([
                'business_id' => $businessId,
                'user_id' => Auth::id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_data' => $this->sanitise($oldData) ?: null,
                'new_data' => $this->sanitise($newData) ?: null,
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function businessIdFor(Model $entity): ?int
    {
        if ($entity->getAttribute('business_id') !== null) {
            return (int) $entity->getAttribute('business_id');
        }

        return property_exists($entity, 'business_id')
            ? (int) $entity->business_id
            : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitise(array $data): array
    {
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }
}
