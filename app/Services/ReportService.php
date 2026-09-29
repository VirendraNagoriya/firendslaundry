<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers shown on the dashboards and in the reports.
 *
 * Every query here goes through the tenant scope, so a figure can never
 * mix one laundry's money with another's. The extra narrowing for a branch
 * user or a field staff member is applied by the caller passing an
 * already-scoped builder.
 */
class ReportService
{
    /**
     * Headline counters for a date range.
     *
     * @return array<string, mixed>
     */
    public function summary(Builder $query, Carbon $from, Carbon $to): array
    {
        $orders = (clone $query)->between($from->toDateString(), $to->toDateString());

        $total = (clone $orders)->count();
        $completed = (clone $orders)->where('order_status', OrderStatus::COMPLETED->value)->count();
        $cancelled = (clone $orders)->where('order_status', OrderStatus::CANCELLED->value)->count();
        $open = $total - $completed - $cancelled;

        $booked = (float) (clone $orders)->sum('total_amount');
        $collected = (float) $this->collectablePayments($query, $from, $to)->sum('amount');
        $outstanding = $this->outstanding($query, $from, $to);

        $awaitingWeigh = (clone $orders)
            ->where('order_status', OrderStatus::PICKED_UP->value)
            ->count();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders' => [
                'total' => $total,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'open' => $open,
                'awaiting_weighing' => $awaitingWeigh,
            ],
            'money' => [
                'booked' => round($booked, 2),
                'collected' => round($collected, 2),
                'outstanding' => round($outstanding, 2),
                'average_order' => $total > 0 ? round($booked / $total, 2) : 0.0,
            ],
        ];
    }

    /**
     * Orders per day, with the value booked on each day.
     *
     * @return list<array{date: string, orders: int, value: float}>
     */
    public function dailySeries(Builder $query, Carbon $from, Carbon $to): array
    {
        $rows = (clone $query)
            ->between($from->toDateString(), $to->toDateString())
            ->get(['created_at', 'total_amount']);

        $grouped = $rows->groupBy(fn (Order $order) => $order->created_at->toDateString());

        $series = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $daily = $grouped->get($key, collect());

            $series[] = [
                'date' => $key,
                'orders' => $daily->count(),
                'value' => round((float) $daily->sum('total_amount'), 2),
            ];
        }

        return $series;
    }

    /**
     * Which services are actually being sold, by revenue.
     *
     * @return Collection<int, object>
     */
    public function topServices(Builder $query, Carbon $from, Carbon $to, int $limit = 10)
    {
        $orderIds = (clone $query)
            ->between($from->toDateString(), $to->toDateString())
            ->pluck('id');

        return OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->join('services', 'services.id', '=', 'order_items.service_id')
            ->groupBy('services.id', 'services.name', 'services.pricing_type')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get([
                'services.id as service_id',
                'services.name as name',
                'services.pricing_type as pricing_type',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(order_items.total_amount) as revenue'),
            ]);
    }

    /**
     * How money came in, by method.
     *
     * @return array<string, float>
     */
    public function collectionsByMethod(Builder $query, Carbon $from, Carbon $to): array
    {
        $rows = $this->collectablePayments($query, $from, $to)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $out = [];

        foreach (PaymentMethod::cases() as $method) {
            $out[$method->label()] = round((float) $rows->get($method->value, 0), 2);
        }

        return $out;
    }

    /**
     * How many orders each field employee has handled, for a leaderboard.
     *
     * @return array<int, array{name: string, role: string, pickups: int, deliveries: int}>
     */
    public function employeeLoad(Collection $staff): array
    {
        return $staff
            ->map(fn (User $employee) => [
                'name' => $employee->name,
                'role' => $employee->role->label(),
                'pickups' => $employee->pickupAssignments()->count(),
                'deliveries' => $employee->deliveryAssignments()->count(),
            ])
            ->sortByDesc(fn (array $row) => $row['pickups'] + $row['deliveries'])
            ->values()
            ->all();
    }

    /**
     * Payments that actually count as money in: anything settled and not
     * voided.
     */
    private function collectablePayments(Builder $query, Carbon $from, Carbon $to): Builder
    {
        $orderIds = (clone $query)
            ->between($from->toDateString(), $to->toDateString())
            ->pluck('id');

        return Payment::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', PaymentStatus::PAID->value);
    }

    /**
     * What is still owed across orders that have not been cancelled.
     */
    private function outstanding(Builder $query, Carbon $from, Carbon $to): float
    {
        $orders = (clone $query)
            ->between($from->toDateString(), $to->toDateString())
            ->where('order_status', '!=', OrderStatus::CANCELLED->value)
            ->get(['id', 'total_amount']);

        if ($orders->isEmpty()) {
            return 0.0;
        }

        $paid = Payment::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('status', PaymentStatus::PAID->value)
            ->selectRaw('order_id, SUM(amount) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        $outstanding = 0.0;

        foreach ($orders as $order) {
            $outstanding += max(0.0, (float) $order->total_amount - (float) $paid->get($order->id, 0));
        }

        return $outstanding;
    }

    /**
     * The orders a given role should see, before any date filter.
     */
    public function ordersVisibleTo(User $user): Builder
    {
        $query = Order::query();

        return match ($user->role) {
            Role::BRANCH => $query->where('branch_id', $user->branch_id),
            Role::PICKUP_BOY => $query->whereHas('pickupAssignments', fn ($q) => $q->where('pickup_boy_id', $user->id)),
            Role::DELIVERY_BOY => $query->whereHas('deliveryAssignments', fn ($q) => $q->where('delivery_boy_id', $user->id)),
            Role::CUSTOMER => $query->where('customer_id', function ($sub) use ($user) {
                $sub->select('id')
                    ->from('customers')
                    ->whereColumn('customers.id', 'orders.customer_id')
                    ->where('customers.mobile', $user->mobile)
                    ->where('customers.business_id', $user->business_id);
            }),
            default => $query,
        };
    }
}
