<?php

use App\Exceptions\BranchLimitExceededException;
use App\Exceptions\SupabaseAuthException;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\FlushTenantContext;
use App\Services\AssignmentException;
use App\Services\OrderTransitionException;
use App\Services\OtpAccountException;
use App\Services\OtpException;
use App\Services\PaymentException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The tenant context is a singleton, so it is reset at the end of
        // every request as well as between queued jobs.
        $middleware->web(append: [
            FlushTenantContext::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // An API call that is not signed in must get a 401 JSON body, not a
        // redirect to a web login page, so that the browser client can tell
        // "session expired" apart from a server error.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /**
         * Render a domain exception as JSON or as a redirect, depending on
         * what the caller asked for. Declared once and reused below.
         */
        $render = function (string $message, int $status, array $extra = []): \Closure {
            return function (Request $request) use ($message, $status, $extra): Response {
                if ($request->expectsJson()) {
                    return response()->json(
                        array_merge(['message' => $message], $extra),
                        $status
                    );
                }

                return back()->with('error', $message);
            };
        };

        // A wrong or expired OTP is a validation problem, and the message
        // is safe to show: it never reveals whether the number is registered.
        $exceptions->render(
            fn (OtpException $e) => $render($e->getMessage(), 422, [
                'reason' => $e->reason,
                'retry_after' => $e->retryAfter,
            ])(request())
        );

        $exceptions->render(
            fn (OtpAccountException $e) => $render($e->getMessage(), 403)(request())
        );

        $exceptions->render(
            fn (BranchLimitExceededException $e) => $render($e->getMessage(), 422, [
                'limit' => $e->limit,
                'used' => $e->used,
            ])(request())
        );

        // 409: the request was well formed but conflicts with the order's
        // current lifecycle state.
        $exceptions->render(
            fn (OrderTransitionException $e) => $render($e->getMessage(), 409)(request())
        );

        $exceptions->render(
            fn (AssignmentException $e) => $render($e->getMessage(), 422)(request())
        );

        $exceptions->render(
            fn (PaymentException $e) => $render($e->getMessage(), 422)(request())
        );

        // A refused or unconfigured Supabase sign-in. The message is safe
        // for the person at the screen; the provider detail stays in the
        // log.
        $exceptions->render(
            fn (SupabaseAuthException $e) => $render($e->getMessage(), 422, array_filter([
                'reason' => $e->reason,
            ]))(request())
        );
    })->create();
