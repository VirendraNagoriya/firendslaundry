<?php

namespace App\Services;

/**
 * Result of pricing one order, broken down so the UI can show the customer
 * exactly what they are being charged for.
 */
final class PriceBreakdown
{
    public function __construct(
        public readonly float $subtotal,
        public readonly float $pickupCharge,
        public readonly float $deliveryCharge,
        public readonly float $discount,
        public readonly float $total,
        public readonly bool $final,
    ) {
    }

    /**
     * An estimate is not final while any KG line is still unweighed
     * (spec section 13): the business bills the measured weight.
     */
    public function isEstimate(): bool
    {
        return ! $this->final;
    }

    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'pickup_charge' => $this->pickupCharge,
            'delivery_charge' => $this->deliveryCharge,
            'discount' => $this->discount,
            'total' => $this->total,
            'is_estimate' => $this->isEstimate(),
        ];
    }
}
