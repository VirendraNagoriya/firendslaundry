<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum PaymentMethod: string
{
    use EnumeratesValues;

    case CASH = 'CASH';
    case UPI = 'UPI';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::UPI => 'UPI',
        };
    }
}
