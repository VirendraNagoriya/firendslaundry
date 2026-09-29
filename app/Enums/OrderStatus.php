<?php

namespace App\Enums;

use App\Enums\Concerns\EnumeratesValues;

enum OrderStatus: string
{
    use EnumeratesValues;

    case ORDER_CREATED = 'ORDER_CREATED';
    case PICKUP_ASSIGNED = 'PICKUP_ASSIGNED';
    case PICKED_UP = 'PICKED_UP';
    case RECEIVED_AT_BRANCH = 'RECEIVED_AT_BRANCH';
    case WEIGHED = 'WEIGHED';
    case PROCESSING = 'PROCESSING';
    case READY = 'READY';
    case DELIVERY_ASSIGNED = 'DELIVERY_ASSIGNED';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case DELIVERED = 'DELIVERED';
    case CUSTOMER_PICKUP = 'CUSTOMER_PICKUP';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::ORDER_CREATED => 'Order Created',
            self::PICKUP_ASSIGNED => 'Pickup Assigned',
            self::PICKED_UP => 'Picked Up',
            self::RECEIVED_AT_BRANCH => 'Received At Branch',
            self::WEIGHED => 'Weighed',
            self::PROCESSING => 'Processing',
            self::READY => 'Ready',
            self::DELIVERY_ASSIGNED => 'Delivery Assigned',
            self::OUT_FOR_DELIVERY => 'Out For Delivery',
            self::DELIVERED => 'Delivered',
            self::CUSTOMER_PICKUP => 'Customer Pickup',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Statuses that can never change again. Once an order is closed the
     * status history is frozen, so historical records stay trustworthy.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }

    /**
     * The happy path defined in the spec, in order. Rendering the timeline
     * uses this list; anything not in it (CUSTOMER_PICKUP) is a branch.
     */
    public static function mainFlow(): array
    {
        return [
            self::ORDER_CREATED,
            self::PICKUP_ASSIGNED,
            self::PICKED_UP,
            self::RECEIVED_AT_BRANCH,
            self::WEIGHED,
            self::PROCESSING,
            self::READY,
            self::DELIVERY_ASSIGNED,
            self::OUT_FOR_DELIVERY,
            self::DELIVERED,
            self::COMPLETED,
        ];
    }

    /**
     * Which statuses may legally follow this one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::ORDER_CREATED => [self::PICKUP_ASSIGNED, self::PICKED_UP, self::CANCELLED],
            self::PICKUP_ASSIGNED => [self::PICKED_UP, self::CANCELLED],
            self::PICKED_UP => [self::RECEIVED_AT_BRANCH, self::CANCELLED],
            self::RECEIVED_AT_BRANCH => [self::WEIGHED, self::PROCESSING, self::CANCELLED],
            self::WEIGHED => [self::PROCESSING, self::CANCELLED],
            self::PROCESSING => [self::READY, self::CANCELLED],
            self::READY => [self::DELIVERY_ASSIGNED, self::CUSTOMER_PICKUP, self::CANCELLED],
            self::DELIVERY_ASSIGNED => [self::OUT_FOR_DELIVERY, self::CANCELLED],
            self::OUT_FOR_DELIVERY => [self::DELIVERED, self::CANCELLED],
            self::DELIVERED => [self::COMPLETED],
            self::CUSTOMER_PICKUP => [self::COMPLETED],
            self::COMPLETED, self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Statuses from which an order still counts as "in flight" for
     * dashboards and reports.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }

    /**
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }
}
