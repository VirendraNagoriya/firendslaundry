<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Every reason an OTP can fail, with a message safe to show the user.
 *
 * The messages are deliberately uniform: a caller cannot tell "no code was
 * ever issued" from "the code expired" from "wrong code", because that
 * distinction would let an attacker probe for valid numbers.
 */
class OtpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid',
        public readonly int $retryAfter = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidCode(int $attemptsRemaining = 0): self
    {
        $suffix = $attemptsRemaining > 0
            ? " {$attemptsRemaining} attempt(s) remaining."
            : ' Request a new code.';

        return new self('That code is not correct.'.$suffix, 'invalid_code');
    }

    public static function expired(): self
    {
        return new self('That code has expired. Request a new one.', 'expired');
    }

    public static function noActiveCode(): self
    {
        return new self('That code is not valid. Request a new one.', 'no_active_code');
    }

    public static function tooManyAttempts(): self
    {
        return new self('Too many incorrect attempts. Request a new code.', 'too_many_attempts');
    }

    public static function tooSoon(int $seconds): self
    {
        return new self(
            "Please wait {$seconds} seconds before requesting another code.",
            'too_soon',
            $seconds
        );
    }

    public static function rateLimited(string $message, int $seconds): self
    {
        return new self($message, 'rate_limited', $seconds);
    }

    public static function deliveryFailed(): self
    {
        return new self(
            'We could not send the code right now. Please try again shortly.',
            'delivery_failed'
        );
    }
}
