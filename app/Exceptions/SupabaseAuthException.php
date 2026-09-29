<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when Supabase refuses a sign-in attempt, or when the app is not
 * configured to talk to Supabase yet.
 */
class SupabaseAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = '',
    ) {
        parent::__construct($message);
    }
}
