<?php

namespace App\Services\Sms;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TextBee transport.
 *
 * TextBee relays an HTTP request to an Android phone running its app, and
 * that phone sends the SMS over its own SIM. So the phone is a production
 * dependency: if it is offline the request is accepted here but the SMS
 * never arrives. That is why a gateway health check belongs in the ops
 * runbook, and why `accepted` is deliberately not called `delivered`.
 *
 * This class is responsible for the HTTP call and nothing else — OTP
 * policy lives in OtpService, message wording in the business templates.
 */
class TextBeeDriver implements SmsDriver
{
    /**
     * API errors worth retrying. 4xx means we sent something wrong, so
     * repeating it would only burn quota.
     */
    private const RETRYABLE_HTTP = [429, 500, 502, 503, 504];

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 10,
        private readonly string $fromName = 'Laundry',
        private readonly string $defaultCountryCode = '91',
    ) {
    }

    public function send(array $recipients, string $message): SmsResult
    {
        if (blank($this->apiKey)) {
            // Loud, and without echoing any part of the key.
            throw new RuntimeException(
                'TEXTBEE_API_KEY is not configured. Set it in the environment, '
                .'or switch SMS_DRIVER=log for local development.'
            );
        }

        $numbers = $this->normalise($recipients);

        if ($numbers === []) {
            return SmsResult::failed('No valid recipient numbers.');
        }

        $payload = json_encode([
            'recipients' => $numbers,
            'message' => $message,
            'smsFrom' => $this->fromName,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $lastError = 'Unknown TextBee error.';
        $lastStatus = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->attempt($payload);

            if ($result->accepted) {
                return $result;
            }

            $lastError = $result->error ?? $lastError;
            $lastStatus = $result->httpStatus;

            if ($lastStatus === null || ! in_array($lastStatus, self::RETRYABLE_HTTP, true)) {
                break;
            }

            // Linear backoff; gateway hiccups are usually brief.
            sleep($attempt * 2);
        }

        return SmsResult::failed($lastError, $lastStatus);
    }

    private function attempt(string $payload): SmsResult
    {
        $handle = curl_init($this->baseUrl);

        if ($handle === false) {
            return SmsResult::failed('Could not initialise cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-api-key: '.$this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            return SmsResult::failed($error !== '' ? $error : 'TextBee request failed.', $status ?: null);
        }

        $decoded = json_decode($body, true);

        if ($status >= 200 && $status < 300) {
            Log::info('TextBee accepted a message', [
                'http_status' => $status,
                'provider_id' => $this->extractProviderId($decoded),
            ]);

            return SmsResult::accepted(
                $this->extractProviderId($decoded),
                $status,
                is_array($decoded) ? $decoded : []
            );
        }

        Log::warning('TextBee rejected a message', [
            'http_status' => $status,
            'response' => $this->safeBody($body),
        ]);

        return SmsResult::failed(
            $this->extractErrorMessage($decoded) ?? 'TextBee responded with HTTP '.$status,
            $status,
            is_array($decoded) ? $decoded : []
        );
    }

    /**
     * @param  list<string>  $recipients
     * @return list<string>
     */
    private function normalise(array $recipients): array
    {
        $numbers = [];

        foreach ($recipients as $recipient) {
            $parsed = PhoneNumber::tryParse((string) $recipient, $this->defaultCountryCode);

            if ($parsed !== null) {
                $numbers[$parsed->e164] = $parsed->e164;
            }
        }

        return array_values($numbers);
    }

    private function extractProviderId(mixed $decoded): ?string
    {
        if (! is_array($decoded)) {
            return null;
        }

        $id = $decoded['data']['id']
            ?? $decoded['id']
            ?? $decoded['data']['messageId']
            ?? $decoded['messageId']
            ?? null;

        return $id === null ? null : (string) $id;
    }

    private function extractErrorMessage(mixed $decoded): ?string
    {
        if (! is_array($decoded)) {
            return null;
        }

        $message = $decoded['message']
            ?? $decoded['error']
            ?? $decoded['data']['message']
            ?? null;

        return is_string($message) && $message !== '' ? $message : null;
    }

    private function safeBody(string $body): string
    {
        return mb_substr($body, 0, 500);
    }
}
