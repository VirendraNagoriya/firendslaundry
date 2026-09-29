<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum FulfilmentType: string
{
    use EnumeratesValues;

    case DELIVERY = 'DELIVERY';
    case CUSTOMER_PICKUP = 'CUSTOMER_PICKUP';

    public function label(): string
    {
        return match ($this) {
            self::DELIVERY => 'Home Delivery',
            self::CUSTOMER_PICKUP => 'Customer Collects',
        };
    }
}
