<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum AssignmentStatus: string
{
    use EnumeratesValues;

    case ASSIGNED = 'ASSIGNED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNED => 'Assigned',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::ASSIGNED, self::IN_PROGRESS], true);
    }
}
