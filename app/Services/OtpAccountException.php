<?php

namespace App\Services;

use RuntimeException;

class OtpAccountException extends RuntimeException
{
    public static function nameRequired(): self
    {
        return new self('Please tell us your name before requesting a code.');
    }

    public static function noAccountFound(): self
    {
        return new self(
            'We could not find an account for this number. '
            .'Please check the number, or ask your laundry to register you first.'
        );
    }

    public static function inactive(): self
    {
        return new self('This account has been deactivated. Please contact your laundry.');
    }
}
