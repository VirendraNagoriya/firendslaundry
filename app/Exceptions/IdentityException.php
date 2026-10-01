<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A person proved who they are, but the app cannot decide which profile to
 * sign them in as.
 *
 * The messages here are shown to the user, so they must never carry
 * provider detail, SQL or stack traces. Anything unexpected is reported as a
 * generic failure on purpose.
 */
class IdentityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid',
    ) {
        parent::__construct($message);
    }

    public static function businessRequired(): self
    {
        return new self(
            'We could not match this account to a laundry. Enter the short code printed on your bill or collection slip.',
            'business_required',
        );
    }

    public static function unknownBusiness(): self
    {
        return new self(
            'That laundry code was not recognised. Check the code on your bill, or ask the laundry for it.',
            'business_unknown',
        );
    }

    public static function mobileRequired(): self
    {
        return new self(
            'Enter the mobile number this laundry has on file for you.',
            'mobile_required',
        );
    }

    public static function nameRequired(): self
    {
        return new self(
            'Please enter your name so the laundry knows who the order is for.',
            'name_required',
        );
    }

    public static function accountDeactivated(): self
    {
        return new self(
            'This account has been deactivated. Please contact your laundry.',
            'account_inactive',
        );
    }

    /**
     * One Google account belongs to one profile, which belongs to one
     * laundry. Saying this plainly is better than silently signing somebody
     * into the wrong tenant.
     */
    public static function alreadyRegisteredElsewhere(?string $laundryName): self
    {
        return new self(
            $laundryName !== null
                ? "This Google account is already registered with {$laundryName}. Use a different Google account for this laundry."
                : 'This Google account is already registered with another laundry. Use a different Google account to sign in here.',
            'already_registered',
        );
    }

    public static function emailTaken(): self
    {
        // No mention of resetting: this app cannot yet prove anybody owns an
        // inbox, so there is no safe reset to point at.
        return new self(
            'An account already exists with that email address. Sign in instead.',
            'email_taken',
        );
    }

    public static function invalidCredentials(): self
    {
        return new self(
            'That email address and password do not match an account.',
            'invalid_credentials',
        );
    }

    public static function emailNotVerified(): self
    {
        return new self(
            'Confirm your email address before signing in. Check your inbox for the link.',
            'email_unverified',
        );
    }

    public static function providerFailed(): self
    {
        return new self(
            'Google sign-in did not complete. Please try again.',
            'provider_failed',
        );
    }
}
