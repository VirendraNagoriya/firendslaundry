<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum PricingType: string
{
    use EnumeratesValues;

    case KG = 'KG';
    case PIECE = 'PIECE';

    public function label(): string
    {
        return match ($this) {
            self::KG => 'Per KG',
            self::PIECE => 'Per Piece',
        };
    }
}
