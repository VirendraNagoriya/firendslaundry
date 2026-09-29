<?php

namespace App\Services\Sms;

/**
 * Outcome of an SMS attempt.
 *
 * `accepted` means the gateway took responsibility for the message, not
 * that it reached the handset — TextBee queues it on the Android phone
 * and delivers over the SIM a moment later.
 */
final class SmsResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerId = null,
        public readonly ?string $error = null,
        public readonly ?int $httpStatus = null,
        public readonly array $raw = [],
    ) {
    }

    public static function accepted(?string $providerId = null, ?int $httpStatus = null, array $raw = []): self
    {
        return new self(true, $providerId, null, $httpStatus, $raw);
    }

    public static function failed(string $error, ?int $httpStatus = null, array $raw = []): self
    {
        return new self(false, null, $error, $httpStatus, $raw);
    }

    public function failedToSend(): bool
    {
        return ! $this->accepted;
    }
}
