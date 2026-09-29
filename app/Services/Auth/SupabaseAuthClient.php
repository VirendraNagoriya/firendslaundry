<?php

namespace App\Services\Auth;

use App\Exceptions\SupabaseAuthException;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to Supabase Auth over its REST API.
 *
 * Deliberately built on Laravel's HTTP client rather than the official
 * PHP SDK: the SDK pins guzzle 7.5 and phpdotenv 5.5, and neither is a
 * version we are willing to downgrade to in an app that handles customer
 * data. The REST surface is stable and fully documented.
 *
 * The rules that matter here:
 *  - the one-time code is generated, sent and checked by Supabase; this
 *    class never sees or stores it, so there is no code to leak from our
 *    database or our logs;
 *  - the service-role key is used only for calls that need admin rights,
 *    and only on the server.
 */
class SupabaseAuthClient
{
    public function __construct(
        private readonly ?string $url = null,
        private readonly ?string $anonKey = null,
        private readonly ?string $serviceRoleKey = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return filled($this->url) && (filled($this->anonKey) || filled($this->serviceRoleKey));
    }

    /**
     * Ask Supabase to send a one-time code to this phone number or email.
     *
     * @return array{message_id: string|null}
     *
     * @throws SupabaseAuthException
     */
    public function sendCode(string $identifier, string $countryCode = '91'): array
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $payload = ['email' => $identifier];
        } else {
            $payload = [
                'phone' => $this->toE164($identifier, $countryCode),
                'options' => ['channel' => 'sms'],
            ];
        }

        $response = $this->request()->post('/otp', $payload);

        if ($response->failed()) {
            $this->fail($response, 'We could not send a login code to that number. Please try again.');
        }

        return ['message_id' => $response->json('message_id')];
    }

    /**
     * Trade the code the person typed for a Supabase session.
     *
     * @return array{user_id: string, access_token: string, phone: string|null, email: string|null}
     *
     * @throws SupabaseAuthException
     */
    public function verifyCode(string $identifier, string $token, string $countryCode = '91'): array
    {
        $payload = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? ['email' => $identifier, 'token' => $token, 'type' => 'email']
            : ['phone' => $this->toE164($identifier, $countryCode), 'token' => $token, 'type' => 'sms'];

        $response = $this->request()->post('/verify', $payload);

        if ($response->failed()) {
            $this->fail($response, 'That code is not valid or has expired. Please request a new one.');
        }

        $user = $response->json('user') ?? [];

        $id = (string) ($user['id'] ?? '');

        if ($id === '') {
            throw new SupabaseAuthException(
                'Supabase accepted the code but returned no user. Check the project settings.'
            );
        }

        return [
            'user_id' => $id,
            'access_token' => (string) ($response->json('access_token') ?? ''),
            'phone' => $user['phone'] ?? null,
            'email' => $user['email'] ?? null,
        ];
    }

    /**
     * Look up an account by id, using the service-role key so it works for
     * accounts that are not signed in.
     *
     * @return array<string, mixed>|null
     */
    public function findUserById(string $id): ?array
    {
        $response = $this->request(withServiceRole: true)->get("/admin/users/{$id}");

        if ($response->failed()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Normalise whatever the person typed into E.164.
     *
     * Delegates to the same helper the rest of the app uses, so a number
     * typed here is byte-for-byte the number stored against a customer.
     * A leading trunk zero ("09876543210") is dropped, and a number that
     * already carries its country code does not get a second one.
     */
    public function toE164(string $input, string $countryCode = '91'): string
    {
        return PhoneNumber::parse($input, $countryCode)->e164;
    }

    /**
     * True when the input looks like something we could send a code to.
     */
    public function isUsableIdentifier(string $input): bool
    {
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        return PhoneNumber::tryParse($input, '91') !== null;
    }

    private function request(bool $withServiceRole = false): PendingRequest
    {
        $key = $withServiceRole ? $this->serviceRoleKey : ($this->anonKey ?? $this->serviceRoleKey);

        if (blank($this->url) || blank($key)) {
            throw new SupabaseAuthException(
                'Supabase is not configured. Set SUPABASE_URL and SUPABASE_ANON_KEY in .env.'
            );
        }

        return Http::withToken($key)
            ->baseUrl(rtrim((string) $this->url, '/').'/auth/v1')
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    private function fail(Response $response, string $message): never
    {
        // The body can contain the phone number and provider detail, so it
        // goes to the log rather than to the person at the screen.
        Log::warning('Supabase auth call failed', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        $known = [
            'otp_expired' => 'That code has expired. Please request a new one.',
            'over_request_rate_limit' => 'Too many codes requested. Please wait a minute.',
            'over_sms_send_rate_limit' => 'Too many codes sent to this number. Please wait a minute.',
            'invalid_credentials' => 'That code is not valid. Please try again.',
        ];

        $code = (string) $response->json('error_code', $response->json('msg', ''));

        throw new SupabaseAuthException($known[$code] ?? $message, $code);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
