<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum OtpPurpose: string
{
    use EnumeratesValues;

    case LOGIN = 'LOGIN';

    public function label(): string
    {
        return match ($this) {
            self::LOGIN => 'Login',
        };
    }
}
