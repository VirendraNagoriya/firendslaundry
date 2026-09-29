<?php

namespace App\Http\Controllers\Web;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderStatusService;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderWebController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly OrderStatusService $statuses,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = $this->reports->ordersVisibleTo($user)
            ->with(['branch', 'customer', 'items.service', 'pickupAssignment.pickupBoy', 'deliveryAssignment.deliveryBoy'])
            ->when($request->filled('status'), fn ($q) => $q->where('order_status', $request->string('status')->value))
            ->when($request->filled('branch'), fn ($q) => $q->where('branch_id', $request->integer('branch')))
            ->when($request->filled('q'), fn ($q) => $q->where(function ($inner) use ($request) {
                $like = '%'.$request->string('q')->value.'%';
                $inner->where('order_number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('mobile', 'like', $like));
            }))
            ->latest('created_at');

        return view('orders.index', [
            'user' => $user,
            'orders' => $query->paginate(25)->withQueryString(),
            'statuses' => OrderStatus::cases(),
            'branches' => $user->isCustomer() || $user->isAdmin() || $user->isSuperAdmin()
                ? ($user->business?->branches()->where('status', 'ACTIVE')->orderBy('name')->get() ?? collect())
                : collect(),
        ]);
    }

    public function show(Request $request, Order $order): View
    {
        $this->authorize('view', $order);

        $order->load([
            'branch', 'customer', 'address', 'items.service', 'payments.collector',
            'statusHistory.changedBy', 'pickupAssignment.pickupBoy', 'deliveryAssignment.deliveryBoy',
        ]);

        return view('orders.show', [
            'order' => $order,
            'nextStatuses' => $order->order_status->allowedTransitions(),
            'canRecordPayment' => $request->user()->can('recordPayment', $order),
            'canRecordWeight' => $request->user()->can('recordWeight', $order),
            'canChangeStatus' => $request->user()->can('changeStatus', $order),
        ]);
    }

    /**
     * Move an order along from the order screen. The legal next steps come
     * from the lifecycle itself, never from a hand-written list on the page.
     */
    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('changeStatus', $order);

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $target = OrderStatus::tryFrom($validated['status']);

        if ($target === null) {
            return back()->withErrors(['status' => 'That status is not part of the lifecycle.']);
        }

        $this->statuses->transition($order, $target, $request->user(), $validated['note'] ?? null);

        return back()->with('status', 'Order moved to '.$target->label().'.');
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('cancel', $order);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->statuses->cancel($order, $request->user(), $validated['reason']);

        return back()->with('status', 'Order '.$order->order_number.' cancelled.');
    }
}
