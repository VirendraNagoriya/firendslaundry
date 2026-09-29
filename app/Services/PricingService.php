<?php

namespace App\Services;

use App\Enums\FulfilmentType;
use App\Enums\PricingType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Works out what an order costs.
 *
 * Two pricing models coexist (spec section 13):
 *  - KG services are quoted on the customer's estimate and finally
 *    billed on the weight the pickup boy or branch records;
 *  - PIECE services are quantity x rate and are final immediately.
 *
 * approx_weight and actual_weight are stored separately and the estimate
 * is never overwritten, so an order always shows what the customer was
 * told and what they were actually charged.
 */
class PricingService
{
    /**
     * Price a proposed set of order lines.
     *
     * @param  Collection<int, array{service: Service, quantity?: int|null, approx_weight?: float|null}>  $lines
     */
    public function priceLines(Collection $lines): float
    {
        return round($lines->sum(function (array $line) {
            return $this->priceLine($line['service'], $line['quantity'] ?? null, $line['approx_weight'] ?? null);
        }), 2);
    }

    /**
     * Price one line from the customer's input, using the estimate.
     */
    public function priceLine(Service $service, ?int $quantity, ?float $approxWeight): float
    {
        if ($service->pricing_type === PricingType::KG) {
            return $service->priceFor((float) ($approxWeight ?? 0));
        }

        return $service->priceFor((float) max(1, (int) $quantity));
    }

    /**
     * Re-price a line once the actual weight is known. For PIECE services
     * the actual weight is informational and the price is unchanged.
     */
    public function priceLineFinal(Service $service, ?int $quantity, ?float $approxWeight, ?float $actualWeight): float
    {
        if ($service->pricing_type === PricingType::KG) {
            return $service->priceFor((float) ($actualWeight ?? $approxWeight ?? 0));
        }

        return $service->priceFor((float) max(1, (int) $quantity));
    }

    /**
     * The figure actually billed for a persisted line.
     */
    public function billableAmount(OrderItem $item): float
    {
        return $this->priceLineFinal(
            $item->service,
            $item->quantity,
            $item->approx_weight === null ? null : (float) $item->approx_weight,
            $item->actual_weight === null ? null : (float) $item->actual_weight
        );
    }

    /**
     * Roll up every line on an order into the charges and grand total.
     *
     * @param  float  $discount  Owner-entered concession.
     */
    public function breakdown(Order $order, float $discount = 0.0): PriceBreakdown
    {
        $subtotal = round($order->items->sum(fn (OrderItem $item) => $this->billableAmount($item)), 2);

        $business = $order->business;

        // A walk-in collected at the counter pays no delivery charge, and
        // the pickup charge only applies when we collect from the customer.
        $pickupCharge = $order->order_source->value === 'WALK_IN' && $order->fulfilment_type === FulfilmentType::CUSTOMER_PICKUP
            ? 0.0
            : (float) ($business?->pickup_charge ?? 0);

        $deliveryCharge = $order->fulfilment_type === FulfilmentType::DELIVERY
            ? (float) ($business?->delivery_charge ?? 0)
            : 0.0;

        $discount = max(0.0, round($discount, 2));
        $total = max(0.0, round($subtotal + $pickupCharge + $deliveryCharge - $discount, 2));

        return new PriceBreakdown(
            subtotal: $subtotal,
            pickupCharge: $pickupCharge,
            deliveryCharge: $deliveryCharge,
            discount: $discount,
            total: $total,
            final: ! $this->hasUnweighedKgLine($order),
        );
    }

    /**
     * @param  Collection<int, array{service: Service, quantity?: int|null, approx_weight?: float|null}>  $lines
     */
    public function isEstimate(Collection $lines): bool
    {
        return $lines->contains(
            fn (array $line) => $line['service']->pricing_type === PricingType::KG
                && (float) ($line['approx_weight'] ?? 0) > 0
        );
    }

    /**
     * An order is only finally priced once every KG line carries a
     * measured weight.
     */
    public function hasUnweighedKgLine(Order $order): bool
    {
        return $order->items->contains(function (OrderItem $item) {
            return $item->service?->pricing_type === PricingType::KG
                && $item->actual_weight === null;
        });
    }
}
