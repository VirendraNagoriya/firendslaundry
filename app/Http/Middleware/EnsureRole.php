<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to an explicit set of roles.
 *
 * This is a coarse gate on top of the policies. The policies remain the
 * real authorisation: they know about branch ownership, region mapping and
 * per-record access, which a role check alone cannot express.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny($request);
        }

        $allowed = array_map(
            fn (Role $role) => $role->value,
            array_map(fn (string $role) => Role::from($role), $roles)
        );

        if (! in_array($user->role->value, $allowed, true)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'You do not have access to this resource.',
            ], 403);
        }

        // Send the user to their own dashboard rather than showing a bare
        // 403 page.
        return redirect()->route('dashboard')->with('error', 'You do not have access to that page.');
    }
}
