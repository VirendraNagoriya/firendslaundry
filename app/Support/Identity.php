<?php

namespace App\Support;

/**
 * Proof that somebody controls an account at some provider.
 *
 * This is the only thing the rest of the app ever sees. Whether the proof
 * came from a Google OAuth callback or from a password checked in this
 * codebase, the result is the same shape, so tenant resolution never has to
 * know or care which one it was.
 *
 * `providerId` is the stable identifier at the provider. For Google that is
 * the "sub" claim: not the email, because an email can be changed or
 * reassigned by the provider, whereas "sub" is permanent for that account.
 */
final class Identity
{
    public const PASSWORD = 'password';

    public const GOOGLE = 'google';

    public function __construct(
        public readonly string $provider,
        public readonly ?string $providerId,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $mobile,
        public readonly ?string $name,
        public readonly ?string $avatar,
    ) {
    }

    /**
     * A first-party account: this codebase checked the password itself, so
     * there is no external provider id to record.
     */
    public static function fromPassword(
        string $email,
        string $name,
        ?string $mobile = null,
        ?string $avatar = null,
        bool $emailVerified = true,
    ): self {
        return new self(
            provider: self::PASSWORD,
            providerId: null,
            email: $email,
            emailVerified: $emailVerified,
            mobile: $mobile,
            name: $name,
            avatar: $avatar,
        );
    }

    /**
     * A Google account. Only call this once Google's own token has been
     * verified; `emailVerified` reflects Google's "email_verified" claim and
     * must never be assumed true.
     */
    public static function fromGoogle(
        string $sub,
        ?string $email,
        bool $emailVerified,
        ?string $name,
        ?string $avatar,
    ): self {
        return new self(
            provider: self::GOOGLE,
            providerId: $sub,
            email: $email,
            emailVerified: $emailVerified,
            mobile: null,
            name: $name,
            avatar: $avatar,
        );
    }

    public function isExternal(): bool
    {
        return $this->provider !== self::PASSWORD;
    }

    /**
     * Whether this proof is strong enough to attach to an account that
     * already exists under a different email. An unverified address is not.
     */
    public function canLinkByEmail(): bool
    {
        return $this->email !== null && $this->emailVerified;
    }
}
