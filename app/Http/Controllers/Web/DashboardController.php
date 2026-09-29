<?php

namespace App\Http\Controllers\Web;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Role-aware dashboard.
 *
 * Each role is shown the numbers they can act on: an owner wants revenue
 * and outstanding cash, a branch wants what is sitting on its bench, a
 * pickup boy wants his own list, a customer wants their laundry.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $from = $this->dateOrDefault($request->query('from'), now()->subDays(29));
        $to = $this->dateOrDefault($request->query('to'), now());

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $orders = $this->reports->ordersVisibleTo($user);

        $view = match (true) {
            $user->isSuperAdmin() => 'dashboard.super-admin',
            $user->isAdmin() => 'dashboard.owner',
            $user->isBranch() => 'dashboard.branch',
            $user->isPickupBoy(), $user->isDeliveryBoy() => 'dashboard.staff',
            default => 'dashboard.customer',
        };

        return view($view, [
            'user' => $user,
            'from' => $from,
            'to' => $to,
            'summary' => $this->reports->summary($orders, $from, $to),
            'series' => $this->reports->dailySeries($orders, $from, $to),
            'collections' => $this->reports->collectionsByMethod($orders, $from, $to),
            'topServices' => $this->reports->topServices($orders, $from, $to, 5),
            'recent' => (clone $orders)
                ->with(['branch', 'customer', 'items.service'])
                ->latest('created_at')
                ->limit(10)
                ->get(),
            'statusCounts' => $this->statusCounts($orders),
            'myJobs' => $this->myJobs($user),
            'services' => Service::query()->where('status', 'ACTIVE')->orderBy('name')->get(),
            'branches' => $user->business?->branches()->where('status', 'ACTIVE')->orderBy('name')->get() ?? collect(),
            'businesses' => Business::query()->where('status', 'ACTIVE')->orderBy('business_name')->get(),
        ]);
    }

    /**
     * Read a date off the query string, falling back when it is missing or
     * nonsense, and never letting the range run backwards.
     */
    private function dateOrDefault(mixed $value, Carbon $default): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value))->startOfDay();
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(Builder $query): array
    {
        $counts = (clone $query)
            ->selectRaw('order_status, COUNT(*) as total')
            ->groupBy('order_status')
            ->pluck('total', 'order_status');

        $out = [];

        foreach (OrderStatus::cases() as $status) {
            $out[$status->value] = (int) $counts->get($status->value, 0);
        }

        return $out;
    }

    /**
     * The next jobs waiting on a field employee.
     */
    private function myJobs(User $user)
    {
        if ($user->isPickupBoy()) {
            return Order::query()
                ->with(['branch', 'customer', 'address'])
                ->whereHas('pickupAssignments', fn ($q) => $q->where('pickup_boy_id', $user->id))
                ->whereIn('order_status', [
                    OrderStatus::PICKUP_ASSIGNED->value,
                    OrderStatus::PICKED_UP->value,
                ])
                ->oldest('created_at')
                ->limit(20)
                ->get();
        }

        if ($user->isDeliveryBoy()) {
            return Order::query()
                ->with(['branch', 'customer', 'address'])
                ->whereHas('deliveryAssignments', fn ($q) => $q->where('delivery_boy_id', $user->id))
                ->whereIn('order_status', [
                    OrderStatus::DELIVERY_ASSIGNED->value,
                    OrderStatus::OUT_FOR_DELIVERY->value,
                ])
                ->oldest('created_at')
                ->limit(20)
                ->get();
        }

        return collect();
    }
}
