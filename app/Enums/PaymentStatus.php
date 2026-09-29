<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum PaymentStatus: string
{
    use EnumeratesValues;

    case PENDING = 'PENDING';
    case PARTIAL = 'PARTIAL';
    case PAID = 'PAID';
    case REFUNDED = 'REFUNDED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PARTIAL => 'Partial',
            self::PAID => 'Paid',
            self::REFUNDED => 'Refunded',
        };
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::PAID, self::REFUNDED], true);
    }
}
