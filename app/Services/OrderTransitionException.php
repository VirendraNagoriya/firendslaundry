<?php

namespace App\Services;

use App\Enums\OrderStatus;
use RuntimeException;

class OrderTransitionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?OrderStatus $from = null,
        public readonly ?OrderStatus $to = null,
    ) {
        parent::__construct($message);
    }

    public static function sameStatus(OrderStatus $status): self
    {
        return new self(
            "Order is already marked {$status->label()}.",
            $status,
            $status
        );
    }

    public static function orderClosed(OrderStatus $current): self
    {
        return new self(
            "This order is {$current->label()} and can no longer be changed.",
            $current,
            null
        );
    }

    public static function illegalTransition(OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            "Cannot move an order from {$from->label()} to {$to->label()}.",
            $from,
            $to
        );
    }
}
