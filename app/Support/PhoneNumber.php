<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Normalises user-supplied mobile numbers into E.164 format
 * (+<country><national number>), which is what TextBee expects.
 *
 * Every OTP send passes through here, so a number that cannot be
 * normalised is rejected before we spend an SMS credit on it.
 */
final class PhoneNumber
{
    /**
     * Digits only, exactly as E.164 allows: leading 1-9, then 7 to 14 more.
     */
    private const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    private function __construct(public readonly string $e164)
    {
    }

    /**
     * Build from a loosely formatted user input such as
     * "09876543210", "+91 98765 43210" or "91-9876543210".
     *
     * @param  string  $defaultCountryCode  Digits only, e.g. "91".
     *
     * @throws InvalidArgumentException when the number is not a valid mobile number.
     */
    public static function parse(string $input, string $defaultCountryCode = '91'): self
    {
        $raw = $input;

        // Keep digits and a leading plus, drop spaces/dashes/parens/dots.
        $cleaned = preg_replace('/[^\d+]/', '', trim($input)) ?? '';

        // A plus anywhere but the front is junk from a paste.
        $plusCount = substr_count($cleaned, '+');
        if ($plusCount > 0 && ! str_starts_with($cleaned, '+')) {
            $cleaned = str_replace('+', '', $cleaned);
        }
        $cleaned = preg_replace('/^\++/', '+', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\++$/', '', $cleaned) ?? $cleaned;

        if ($cleaned === '' || ! preg_match('/^\+?\d+$/', $cleaned)) {
            throw new InvalidArgumentException('Mobile number must contain digits only.');
        }

        // "0091..." is the international prefix form of "+91...".
        if (str_starts_with($cleaned, '+00')) {
            $cleaned = '+'.substr($cleaned, 3);
        } elseif (str_starts_with($cleaned, '00')) {
            $cleaned = '+'.substr($cleaned, 2);
        }

        if (! str_starts_with($cleaned, '+')) {
            $digits = $cleaned;
            $country = preg_replace('/\D/', '', $defaultCountryCode) ?: '91';

            // National trunk format: 0 followed by the national number.
            if (str_starts_with($digits, '0') && strlen($digits) === 11) {
                $digits = substr($digits, 1);
            }

            // Already carries the country code (e.g. 919876543210 or 919876543210).
            if (str_starts_with($digits, $country) && strlen($digits) === 2 + 10) {
                $cleaned = '+'.$digits;
            } else {
                $cleaned = '+'.$country.$digits;
            }
        }

        if (! preg_match(self::E164_PATTERN, $cleaned)) {
            throw new InvalidArgumentException('That mobile number does not look valid.');
        }

        return new self($cleaned);
    }

    /**
     * Non-throwing variant, for validation rules and bulk imports.
     */
    public static function tryParse(string $input, string $defaultCountryCode = '91'): ?self
    {
        try {
            return self::parse($input, $defaultCountryCode);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function isValid(string $input, string $defaultCountryCode = '91'): bool
    {
        return self::tryParse($input, $defaultCountryCode) !== null;
    }

    /**
     * The national number without the country code, e.g. 9876543210.
     */
    public function national(string $defaultCountryCode = '91'): string
    {
        $country = preg_replace('/\D/', '', $defaultCountryCode) ?: '91';

        return str_starts_with($this->e164, '+'.$country)
            ? substr($this->e164, 2 + strlen($country))
            : ltrim($this->e164, '+');
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
