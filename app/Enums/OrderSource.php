<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum OrderSource: string
{
    use EnumeratesValues;

    case CUSTOMER = 'CUSTOMER';
    case WALK_IN = 'WALK_IN';
    case ADMIN = 'ADMIN';
    case BRANCH = 'BRANCH';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => 'Online (Customer)',
            self::WALK_IN => 'Walk-in',
            self::ADMIN => 'Created by Admin',
            self::BRANCH => 'Created by Branch',
        };
    }
}
