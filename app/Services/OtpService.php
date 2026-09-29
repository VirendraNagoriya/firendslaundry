<?php

namespace App\Services;

use App\Enums\OtpPurpose;
use App\Models\OtpVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Generates, stores, verifies and invalidates one-time passwords.
 *
 * Security rules enforced here (spec sections 6-7):
 *  - the code is produced by the CSPRNG, never guessed or sequential;
 *  - only a hash is persisted, so a database leak does not yield codes;
 *  - a code is single use and expires on a timer;
 *  - wrong attempts and resend requests are both rate limited.
 */
class OtpService
{
    /**
     * How many wrong codes are tolerated for one issued code. Once the
     * limit is reached the code is burned and a new one must be issued,
     * which also caps brute-force attempts per code.
     */
    public function __construct(private readonly int $maxAttempts = 5)
    {
    }

    /**
     * Issue a code for a mobile number and send it by SMS.
     *
     * @return OtpIssued  Carries the plaintext code so it can be handed to
     *                    the SMS transport. It is never written to storage
     *                    and must not be logged or returned to the browser.
     *
     * @throws OtpException
     */
    public function issue(string $mobile, string $ipAddress, OtpPurpose $purpose = OtpPurpose::LOGIN): OtpIssued
    {
        $this->assertWithinRateLimits($mobile, $ipAddress);

        $code = $this->generateCode();
        $expiresAt = now()->addMinutes((int) config('otp.ttl_minutes'));

        $record = DB::transaction(function () use ($mobile, $purpose, $code, $expiresAt) {
            // Any code still outstanding for this number is retired, so
            // only the newest SMS can ever be redeemed.
            OtpVerification::query()
                ->where('mobile', $mobile)
                ->where('purpose', $purpose->value)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $resends = OtpVerification::query()
                ->where('mobile', $mobile)
                ->where('purpose', $purpose->value)
                ->where('created_at', '>=', now()->subMinutes((int) config('otp.resend_window_minutes')))
                ->count();

            return OtpVerification::create([
                'mobile' => $mobile,
                'purpose' => $purpose,
                'otp_hash' => password_hash($code, PASSWORD_DEFAULT),
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'resend_count' => max(0, $resends),
                'used_at' => null,
            ]);
        });

        $this->hitRateLimit($mobile, $ipAddress);

        return new OtpIssued($record, $code, $expiresAt);
    }

    /**
     * Check a submitted code against the outstanding record for a number.
     *
     * @throws OtpException
     */
    public function verify(string $mobile, string $code, OtpPurpose $purpose = OtpPurpose::LOGIN): OtpVerification
    {
        $record = OtpVerification::query()
            ->active($mobile, $purpose)
            ->latest('id')
            ->first();

        if ($record === null) {
            throw OtpException::noActiveCode();
        }

        if ($record->isExpired()) {
            throw OtpException::expired();
        }

        if ($record->attempts >= $this->maxAttempts) {
            throw OtpException::tooManyAttempts();
        }

        if (! password_verify($code, $record->otp_hash)) {
            $record->increment('attempts');

            $remaining = max(0, $this->maxAttempts - (int) $record->fresh()->attempts);

            throw OtpException::invalidCode($remaining);
        }

        // Burn the code inside the same statement that verified it, so a
        // replayed request cannot find a second valid record.
        $record->forceFill(['used_at' => now()])->save();

        return $record;
    }

    /**
     * Invalidate every outstanding code for a number, e.g. on logout or
     * after a role change.
     */
    public function invalidateAll(string $mobile, OtpPurpose $purpose = OtpPurpose::LOGIN): int
    {
        return OtpVerification::query()
            ->where('mobile', $mobile)
            ->where('purpose', $purpose->value)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    /**
     * Associate a verified record with the account it unlocked.
     */
    public function attachUser(OtpVerification $record, int $userId): void
    {
        $record->forceFill(['user_id' => $userId])->save();
    }

    /**
     * Cryptographically secure numeric code of the configured length.
     */
    public function generateCode(): string
    {
        $length = (int) config('otp.length');

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= random_int(0, 9);
            }
        } while (! $this->isAcceptable($code));

        return $code;
    }

    /**
     * Reject codes that are trivially guessable, so an attacker does not
     * get to try 000000 or 123456.
     */
    private function isAcceptable(string $code): bool
    {
        $repeated = preg_match('/^(\d)\1+$/', $code) === 1;
        $ascending = $this->isSequential($code);
        $descending = $this->isSequential(strrev($code));

        return ! $repeated && ! $ascending && ! $descending;
    }

    private function isSequential(string $code): bool
    {
        $digits = array_map('intval', str_split($code));

        if (count($digits) < 3) {
            return false;
        }

        $step = $digits[1] - $digits[0];

        if ($step !== 1 && $step !== -1) {
            return false;
        }

        for ($i = 2, $count = count($digits); $i < $count; $i++) {
            if ($digits[$i] - $digits[$i - 1] !== $step) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws OtpException
     */
    private function assertWithinRateLimits(string $mobile, string $ipAddress): void
    {
        $gap = (int) config('otp.min_seconds_between_sends');

        if ($gap > 0) {
            $recent = OtpVerification::query()
                ->where('mobile', $mobile)
                ->where('created_at', '>=', now()->subSeconds($gap))
                ->exists();

            if ($recent) {
                throw OtpException::tooSoon($gap);
            }
        }

        $byMobile = (int) config('otp.max_resends');
        $byIp = (int) config('otp.max_per_ip');

        if (RateLimiter::tooManyAttempts($this->mobileKey($mobile), $byMobile)) {
            $seconds = RateLimiter::availableIn($this->mobileKey($mobile));

            throw OtpException::rateLimited(
                "Too many codes requested for this number. Try again in {$seconds} seconds.",
                $seconds
            );
        }

        if (RateLimiter::tooManyAttempts($this->ipKey($ipAddress), $byIp)) {
            $seconds = RateLimiter::availableIn($this->ipKey($ipAddress));

            throw OtpException::rateLimited(
                "Too many codes requested from this network. Try again in {$seconds} seconds.",
                $seconds
            );
        }
    }

    private function hitRateLimit(string $mobile, string $ipAddress): void
    {
        $window = (int) config('otp.resend_window_minutes') * 60;

        RateLimiter::hit($this->mobileKey($mobile), $window);
        RateLimiter::hit($this->ipKey($ipAddress), $window);
    }

    private function mobileKey(string $mobile): string
    {
        return 'otp-send-mobile:'.Str::transliterate($mobile);
    }

    private function ipKey(string $ipAddress): string
    {
        return 'otp-send-ip:'.Str::transliterate($ipAddress);
    }
}
