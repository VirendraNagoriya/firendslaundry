<?php

namespace App\Services;

use App\Models\OtpVerification;
use Illuminate\Support\Carbon;

/**
 * A freshly issued code.
 *
 * `$code` exists for exactly one purpose: handing the value to the SMS
 * transport. It must never be persisted, logged, or returned in an HTTP
 * response. The database holds only the hash inside `$record`.
 */
final class OtpIssued
{
    public function __construct(
        public readonly OtpVerification $record,
        public readonly string $code,
        public readonly Carbon $expiresAt,
    ) {
    }

    public function minutesRemaining(): int
    {
        return max(0, (int) now()->diffInMinutes($this->expiresAt, false));
    }

    /**
     * @return array{mobile: string, expires_in_minutes: int, resend_in_seconds: int}
     *
     * The safe shape to hand back to the browser.
     */
    public function toPublicArray(): array
    {
        return [
            'mobile' => $this->record->mobile,
            'expires_in_minutes' => (int) config('otp.ttl_minutes'),
            'resend_in_seconds' => (int) config('otp.min_seconds_between_sends'),
        ];
    }
}
