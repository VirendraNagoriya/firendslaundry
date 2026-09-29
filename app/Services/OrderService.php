<?php

namespace App\Services;

use App\Enums\FulfilmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and maintains orders.
 *
 * Every write happens inside a transaction: an order, its lines, its
 * opening status entry and its money totals either all land or none do,
 * so we never end up with a half-built order (spec section 22).
 */
class OrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly OrderStatusService $status,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param  array{
     *     branch: Branch,
     *     customer?: Customer|null,
     *     address?: CustomerAddress|null,  (optional; omit for counter orders)
     *     source: OrderSource,
     *     fulfilment?: FulfilmentType,
     *     lines: Collection<int, array{service_id: int, quantity?: int, approx_weight?: float|null}>,
     *     discount?: float,
     *     notes?: string|null,
     *     created_by: User|null
     * }  $data
     */
    public function create(array $data): Order
    {
        $business = $data['branch']->business;

        $order = DB::transaction(function () use ($data, $business) {
            $order = Order::create([
                'business_id' => $business->id,
                'branch_id' => $data['branch']->id,
                'customer_id' => $data['customer']?->id ?? null,
                'address_id' => ($data['address'] ?? null)?->id,
                'order_number' => $this->generateOrderNumber($business),
                'order_source' => $data['source'],
                'order_status' => OrderStatus::ORDER_CREATED,
                'fulfilment_type' => $data['fulfilment'] ?? FulfilmentType::DELIVERY,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by']?->id,
            ]);

            $approxTotal = 0.0;
            $hasKg = false;

            foreach (collect($data['lines']) as $line) {
                $service = Service::query()
                    ->withoutGlobalScopes()
                    ->where('business_id', $business->id)
                    ->findOrFail($line['service_id']);

                $approxWeight = isset($line['approx_weight']) ? (float) $line['approx_weight'] : null;
                $quantity = (int) ($line['quantity'] ?? 1);

                $amount = $this->pricing->priceLine($service, $quantity, $approxWeight);

                OrderItem::create([
                    'order_id' => $order->id,
                    'service_id' => $service->id,
                    'quantity' => $quantity,
                    'approx_weight' => $approxWeight,
                    'actual_weight' => null,
                    // The rate is copied onto the line so a later price
                    // change never rewrites a historical order.
                    'unit_price' => $service->rate,
                    'total_amount' => $amount,
                ]);

                $approxTotal += (float) ($approxWeight ?? 0);
                $hasKg = $hasKg || $service->isPricedByWeight();
            }

            $order->forceFill(['approx_weight' => $hasKg ? $approxTotal : null])->save();

            $this->status->recordCreated($order, $data['created_by'], 'Order created');

            $order->load('items.service');
            $this->applyPricing($order, (float) ($data['discount'] ?? 0));

            return $order;
        });

        $this->audit->created($order, [
            'order_number' => $order->order_number,
            'source' => $order->order_source->value,
            'total' => $order->total_amount,
        ]);

        return $order->fresh(['items.service', 'branch', 'customer', 'address']);
    }

    /**
     * Record measured weights and re-price the order.
     *
     * The customer's original estimate is left untouched; only
     * actual_weight is written, and the order's money columns are
     * recomputed from the measured figures.
     *
     * @param  array<int, array{item_id: int, actual_weight: float|null, quantity?: int}>  $weights
     */
    public function recordActualWeights(Order $order, array $weights, ?User $actor = null): Order
    {
        if ($order->isTerminal()) {
            throw OrderTransitionException::orderClosed($order->order_status);
        }

        DB::transaction(function () use ($order, $weights, $actor) {
            $actualTotal = 0.0;
            $anyRecorded = false;

            foreach ($weights as $input) {
                $item = $order->items()
                    ->whereKey($input['item_id'])
                    ->firstOrFail();

                $actual = isset($input['actual_weight']) && $input['actual_weight'] !== null
                    ? round((float) $input['actual_weight'], 3)
                    : null;

                $changes = ['actual_weight' => $actual];

                if (isset($input['quantity'])) {
                    $changes['quantity'] = (int) $input['quantity'];
                }

                $item->forceFill($changes);

                // Price from the in-memory values, not a re-read: the
                // weight we just captured is not in the database yet.
                $item->forceFill([
                    'total_amount' => $this->pricing->priceLineFinal(
                        $item->service,
                        $item->quantity,
                        $item->approx_weight === null ? null : (float) $item->approx_weight,
                        $item->actual_weight === null ? null : (float) $item->actual_weight,
                    ),
                ])->save();

                if ($item->service?->isPricedByWeight()) {
                    $actualTotal += (float) ($item->actual_weight ?? $item->approx_weight ?? 0);
                    $anyRecorded = true;
                }

                $this->audit->updated($item, $changes);
            }

            $order->load('items.service');

            $order->forceFill([
                'actual_weight' => $anyRecorded ? $actualTotal : $order->actual_weight,
            ])->save();

            $this->applyPricing($order, (float) $order->discount);
        });

        return $order->fresh(['items.service']);
    }

    /**
     * Recompute subtotal, charges and total from the current line prices.
     */
    public function applyPricing(Order $order, float $discount = 0.0): Order
    {
        $order->loadMissing('items.service');
        $breakdown = $this->pricing->breakdown($order, $discount);

        $order->forceFill([
            'subtotal' => $breakdown->subtotal,
            'pickup_charge' => $breakdown->pickupCharge,
            'delivery_charge' => $breakdown->deliveryCharge,
            'discount' => $breakdown->discount,
            'total_amount' => $breakdown->total,
        ])->save();

        return $order;
    }

    /**
     * Per-business sequential order number, e.g. ABC-000123.
     *
     * The counter is derived from the current row count inside the
     * creating transaction, so duplicates are impossible for a business.
     */
    private function generateOrderNumber(Business $business): string
    {
        $prefix = Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', $business->business_name) ?: 'LDR', 0, 3));
        $sequence = Order::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->count() + 1;

        return sprintf('%s-%06d', $prefix, $sequence);
    }

    /**
     * Orders due today, for a branch dashboard.
     */
    public function dueToday(Business $business, ?Branch $branch = null, int $limit = 50): Collection
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
            ->whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
