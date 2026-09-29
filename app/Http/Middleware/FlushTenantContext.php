<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clears the tenant context at the end of a request.
 *
 * The context is a singleton, so under a long-lived worker (queue, Octane)
 * a value pinned by one job could otherwise be read by the next one and
 * leak data across tenants.
 */
class FlushTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->tenant->flush();
    }
}
