<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes the immutable status trail for an order.
 *
 * Every transition is recorded with who made it and when, so the customer
 * timeline and any later dispute can be reconstructed. The history itself
 * is append-only: there is no method here that edits a past row.
 */
class OrderStatusService
{
    /**
     * Record the opening entry for a brand new order.
     */
    public function recordCreated(Order $order, ?User $actor, ?string $note = null): OrderStatusHistory
    {
        return $this->write($order, null, OrderStatus::ORDER_CREATED, $actor, $note);
    }

    /**
     * Move an order forward, refusing any transition the lifecycle does
     * not allow.
     *
     * @throws OrderTransitionException
     */
    public function transition(
        Order $order,
        OrderStatus $target,
        ?User $actor,
        ?string $note = null,
    ): OrderStatusHistory {
        $current = $order->order_status;

        if ($current === $target) {
            throw OrderTransitionException::sameStatus($target);
        }

        if ($order->isTerminal()) {
            throw OrderTransitionException::orderClosed($current);
        }

        if (! $this->isReachable($current, $target)) {
            throw OrderTransitionException::illegalTransition($current, $target);
        }

        return DB::transaction(function () use ($order, $current, $target, $actor, $note) {
            $order->forceFill(['order_status' => $target]);

            if ($target->isTerminal() && $order->completed_at === null) {
                $order->completed_at = now();
            }

            $order->save();

            return $this->write($order, $current, $target, $actor, $note);
        });
    }

    /**
     * Cancel an order. Allowed from any open status, and always recorded
     * (spec section 14) so a cancellation is never silent.
     *
     * @throws OrderTransitionException
     */
    public function cancel(Order $order, ?User $actor, ?string $reason = null): OrderStatusHistory
    {
        return $this->transition($order, OrderStatus::CANCELLED, $actor, $reason);
    }

    /**
     * Whether the target can be reached from the current status.
     *
     * The lifecycle is a graph, not a straight line: a walk-in order never
     * has a pickup, and a counter collection never has a delivery, so
     * several hops can legitimately be skipped. Walk the forward edges and
     * see whether the target is anywhere downstream.
     */
    private function isReachable(OrderStatus $from, OrderStatus $target): bool
    {
        if ($from->canTransitionTo($target)) {
            return true;
        }

        $seen = [];
        $frontier = [$from];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $status) {
                foreach ($status->allowedTransitions() as $candidate) {
                    if ($candidate === $target) {
                        return true;
                    }

                    if (! isset($seen[$candidate->value])) {
                        $seen[$candidate->value] = true;
                        $next[] = $candidate;
                    }
                }
            }

            $frontier = $next;
        }

        return false;
    }

    private function write(
        Order $order,
        ?OrderStatus $from,
        OrderStatus $to,
        ?User $actor,
        ?string $note,
    ): OrderStatusHistory {
        return OrderStatusHistory::create([
            'order_id' => $order->id,
            'old_status' => $from,
            'new_status' => $to,
            'changed_by' => $actor?->id,
            'note' => $note,
        ]);
    }
}
