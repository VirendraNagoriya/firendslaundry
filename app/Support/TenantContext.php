<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves which tenant (business) the current request is acting within.
 *
 * The business id is derived from the *authenticated session user*, never
 * from a request parameter, form field or header. That is the single
 * guarantee behind the isolation rules in the spec: a browser cannot ask
 * for another tenant's data by sending a different business_id.
 *
 * Three states are possible:
 *  - forced:      an explicit business was set for this unit of work
 *                 (Super Admin acting on behalf of a client, or a queued
 *                 job for a specific tenant).
 *  - authenticated: a tenant user is signed in, so their business_id wins.
 *  - unrestricted: nobody is signed in (artisan commands, seeders), so no
 *                 scope is applied at all.
 */
class TenantContext
{
    private ?int $forcedBusinessId = null;

    private bool $bypassed = false;

    /**
     * Pin every tenant-scoped query to a business for the current
     * unit of work. Use with a callback so the pin cannot leak.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function force(int $businessId, callable $callback): mixed
    {
        $previousId = $this->forcedBusinessId;
        $previousBypass = $this->bypassed;

        $this->forcedBusinessId = $businessId;
        $this->bypassed = false;

        try {
            return $callback();
        } finally {
            $this->forcedBusinessId = $previousId;
            $this->bypassed = $previousBypass;
        }
    }

    /**
     * Run a callback with tenant scoping switched off. Reserved for
     * platform-level work such as Super Admin cross-tenant reports.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutTenancy(callable $callback): mixed
    {
        $previous = $this->bypassed;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previous;
        }
    }

    public function bypassed(): bool
    {
        return $this->bypassed;
    }

    /**
     * The business id tenant-scoped queries must filter on, or null when
     * the current context is not restricted to one tenant.
     */
    public function resolve(): ?int
    {
        if ($this->bypassed) {
            return null;
        }

        if ($this->forcedBusinessId !== null) {
            return $this->forcedBusinessId;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        // The Super Admin is platform-level and deliberately not scoped to
        // a single tenant; see TenantScope for how they opt in per query.
        if ($user->role === Role::SUPER_ADMIN) {
            return null;
        }

        return $user->business_id === null ? null : (int) $user->business_id;
    }

    public function isRestricted(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * Reset state. Called between requests and by tests.
     */
    public function flush(): void
    {
        $this->forcedBusinessId = null;
        $this->bypassed = false;
    }
}
