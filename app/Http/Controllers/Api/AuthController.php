<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SupabaseAuthException;
use App\Services\Auth\SupabaseAuthClient;
use App\Services\Auth\SupabaseAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * JSON sign-in for API and Alpine clients.
 *
 * Supabase Auth generates, sends and verifies the one-time code, so
 * nothing here can leak it: this app only ever sees the resulting user id
 * and the fact that the code was accepted. The service-role key stays on
 * the server and never appears in a response.
 *
 * The same flow backs the browser pages in AuthWebController, so there is
 * one way to sign in, not two.
 */
class AuthController extends ApiController
{
    public function __construct(
        private readonly SupabaseAuthService $auth,
        private readonly SupabaseAuthClient $supabase,
    ) {
    }

    /**
     * Step 1: ask Supabase to send a one-time code.
     */
    public function sendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
        ]);

        $identifier = $this->identifier($validated);

        try {
            $this->auth->sendLoginCode($identifier);
        } catch (SupabaseAuthException $e) {
            return $this->fail($e->getMessage(), 503, array_filter([
                'reason' => $e->reason,
            ]));
        }

        return $this->ok(
            [
                'identifier' => $identifier,
                'expires_in_minutes' => (int) config('supabase.otp_expiry_minutes'),
            ],
            'A login code has been sent.',
        );
    }

    /**
     * Step 2: trade the code for a session.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'code' => ['required', 'string', 'min:4', 'max:12'],
            'business_code' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $user = $this->auth->completeLogin(
                identifier: $this->identifier($validated),
                code: $validated['code'],
                businessCode: $validated['business_code'] ?? null,
                name: $validated['name'] ?? null,
            );
        } catch (SupabaseAuthException $e) {
            return $this->fail($e->getMessage(), 422, array_filter([
                'reason' => $e->reason,
            ]));
        }

        return $this->ok([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'mobile' => $user->mobile,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'business_id' => $user->business_id,
                'branch_id' => $user->branch_id,
            ],
            'configured' => $this->supabase->isConfigured(),
            'redirect_to' => config('supabase.redirect_to'),
        ], 'Signed in.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout();

        return $this->ok(null, 'Signed out.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function identifier(array $validated): string
    {
        $identifier = $validated['mobile'] ?? $validated['email'] ?? null;

        if (! is_string($identifier) || trim($identifier) === '') {
            throw ValidationException::withMessages([
                'mobile' => ['Enter the mobile number or email you sign in with.'],
            ]);
        }

        return trim($identifier);
    }
}
