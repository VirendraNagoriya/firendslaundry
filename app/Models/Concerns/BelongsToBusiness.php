<?php

namespace App\Models\Concerns;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned data.
 *
 * Applying this trait does two things:
 *  1. adds business_id to every INSERT automatically, taken from the
 *     signed-in user rather than from request input;
 *  2. registers TenantScope so every read is filtered to that tenant.
 *
 * @property int $business_id
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model->business_id !== null) {
                return;
            }

            $user = auth()->user();

            if ($user && $user->role !== Role::SUPER_ADMIN && $user->business_id !== null) {
                $model->business_id = (int) $user->business_id;
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Run a callback with every tenant-scoped query pinned to a specific
     * business, regardless of who is signed in. Used by the Super Admin to
     * inspect one client, and by queued jobs that carry a business id.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function forBusiness(int $businessId, callable $callback): mixed
    {
        return app(TenantContext::class)->force($businessId, $callback);
    }
}
