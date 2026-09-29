<?php

namespace App\Http\Controllers\Api;

use App\Enums\FulfilmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends ApiController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderStatusService $status,
        private readonly AssignmentService $assignments,
        private readonly PaymentService $payments,
    ) {
    }

    /**
     * Orders the signed-in user is allowed to see, already narrowed to the
     * tenant by the global scope.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewAny', Order::class);

        $perPage = min(100, (int) $request->integer('per_page', 25));

        $query = Order::query()
            ->with(['branch', 'customer', 'items.service', 'pickupAssignment.pickupBoy', 'deliveryAssignment.deliveryBoy'])
            ->when($request->filled('status'), fn ($q) => $q->where('order_status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->boolean('open'), fn ($q) => $q->open())
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->latest('created_at');

        // Row-level scoping per role. The tenant filter is already applied
        // by the global scope; this narrows within the tenant.
        match ($user->role) {
            Role::BRANCH => $query->where('branch_id', $user->branch_id),
            Role::PICKUP_BOY => $query->whereHas(
                'pickupAssignments',
                fn ($q) => $q->where('pickup_boy_id', $user->id)
            ),
            Role::DELIVERY_BOY => $query->whereHas(
                'deliveryAssignments',
                fn ($q) => $q->where('delivery_boy_id', $user->id)
            ),
            Role::CUSTOMER => $query->where('customer_id', $this->customerIdFor($user)),
            default => $query,
        };

        return $this->ok($query->paginate($perPage));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load([
            'branch', 'customer', 'address', 'items.service', 'statusHistory.changedBy',
            'pickupAssignment.pickupBoy', 'deliveryAssignment.deliveryBoy', 'payments.collector',
        ]);

        return $this->ok($this->present($order));
    }

    /**
     * Create an order. The channel is decided by the signed-in role, never
     * by the request body.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('create', Order::class);

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'mobile' => ['nullable', 'string', 'max:25'],
            'name' => ['nullable', 'string', 'max:120'],
            'address_id' => ['nullable', 'integer'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'fulfilment' => ['nullable', Rule::in(FulfilmentType::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_id' => ['required', 'integer'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.approx_weight' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ]);

        $branch = Branch::findOrFail($validated['branch_id']);
        Gate::authorize('view', $branch);

        $customer = $this->resolveCustomer($user, $validated);
        $address = $this->resolveAddress($customer, $validated);

        if ($user->isBranch() && $user->branch_id !== $branch->id) {
            throw ValidationException::withMessages([
                'branch_id' => ['You can only create orders for your own branch.'],
            ]);
        }

        $order = $this->orders->create([
            'branch' => $branch,
            'customer' => $customer,
            'address' => $address,
            'source' => $this->sourceFor($user),
            'fulfilment' => FulfilmentType::tryFrom($validated['fulfilment'] ?? '') ?? $this->defaultFulfilment($address),
            'lines' => collect($validated['lines'])->map(fn (array $line) => [
                'service_id' => (int) $line['service_id'],
                'quantity' => $line['quantity'] ?? null,
                'approx_weight' => $line['approx_weight'] ?? null,
            ]),
            'discount' => (float) ($validated['discount'] ?? 0),
            'notes' => $validated['notes'] ?? null,
            'created_by' => $user,
        ]);

        return $this->ok($this->present($order->load(['items.service', 'branch', 'customer'])), 'Order created.', 201);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorize('changeStatus', $order);

        $validated = $request->validate([
            'status' => ['required', Rule::in(OrderStatus::values())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $target = OrderStatus::from($validated['status']);
        $history = $this->status->transition($order, $target, $request->user(), $validated['note'] ?? null);

        return $this->ok([
            'order' => $this->present($order->fresh(['items.service'])),
            'transition' => [
                'from' => $history->old_status?->value,
                'to' => $history->new_status->value,
                'at' => $history->created_at?->toIso8601String(),
            ],
        ], 'Order status updated.');
    }

    public function assignPickup(Request $request, Order $order): JsonResponse
    {
        $this->authorize('assignPickup', $order);

        $validated = $request->validate([
            'pickup_boy_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = User::findOrFail($validated['pickup_boy_id']);

        $assignment = $this->assignments->assignPickup(
            $order, $employee, $request->user(), $validated['note'] ?? null
        );

        return $this->ok([
            'assignment_id' => $assignment->id,
            'pickup_boy' => $employee->only(['id', 'name', 'mobile']),
            'order_status' => $order->fresh()->order_status->value,
        ], 'Pickup assigned.');
    }

    public function assignDelivery(Request $request, Order $order): JsonResponse
    {
        $this->authorize('assignDelivery', $order);

        $validated = $request->validate([
            'delivery_boy_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = User::findOrFail($validated['delivery_boy_id']);

        $assignment = $this->assignments->assignDelivery(
            $order, $employee, $request->user(), $validated['note'] ?? null
        );

        return $this->ok([
            'assignment_id' => $assignment->id,
            'delivery_boy' => $employee->only(['id', 'name', 'mobile']),
            'order_status' => $order->fresh()->order_status->value,
        ], 'Delivery assigned.');
    }

    /**
     * Record measured weights and re-price the order.
     */
    public function recordWeight(Request $request, Order $order): JsonResponse
    {
        $this->authorize('recordWeight', $order);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.actual_weight' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->assertItemsBelongToOrder($order, $validated['items']);

        $order = $this->orders->recordActualWeights(
            $order,
            $validated['items'],
            $request->user(),
        );

        return $this->ok($this->present($order), 'Weight recorded and totals updated.');
    }

    public function recordPayment(Request $request, Order $order): JsonResponse
    {
        $this->authorize('recordPayment', $order);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $this->payments->record(
            $order,
            (float) $validated['amount'],
            PaymentMethod::from($validated['method']),
            $request->user(),
            $validated['reference_no'] ?? null,
            $validated['notes'] ?? null,
        );

        $order->refresh();

        return $this->ok([
            'payment' => $payment->only(['id', 'amount', 'method', 'status', 'reference_no', 'collected_at']),
            'order' => $this->present($order),
        ], 'Payment recorded.');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Customers place their own orders; staff may place one on behalf of
     * a customer, selecting them by mobile.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveCustomer(User $user, array $validated): ?Customer
    {
        if ($user->isCustomer()) {
            $customer = $this->customerFor($user);

            if ($customer === null) {
                throw ValidationException::withMessages([
                    'mobile' => ['No customer record is linked to this number for this laundry.'],
                ]);
            }

            return $customer;
        }

        if (! empty($validated['customer_id'])) {
            return Customer::findOrFail($validated['customer_id']);
        }

        if (empty($validated['mobile'])) {
            throw ValidationException::withMessages([
                'mobile' => ['Select an existing customer or enter a mobile number for a new one.'],
            ]);
        }

        $mobile = \App\Support\PhoneNumber::tryParse(
            $validated['mobile'],
            (string) config('sms.default_country_code')
        )?->e164;

        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => ['Enter a valid mobile number.'],
            ]);
        }

        $existing = Customer::query()
            ->where('mobile', $mobile)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $name = trim((string) ($validated['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['A name is required when creating a new customer.'],
            ]);
        }

        return Customer::create([
            'name' => $name,
            'mobile' => $mobile,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveAddress(?Customer $customer, array $validated): ?CustomerAddress
    {
        if (! empty($validated['address_id'])) {
            $address = CustomerAddress::findOrFail($validated['address_id']);

            if ($address->customer_id !== $customer?->id) {
                throw ValidationException::withMessages([
                    'address_id' => ['That address does not belong to this customer.'],
                ]);
            }

            return $address;
        }

        if (empty($validated['address_line']) || $customer === null) {
            return $customer?->defaultAddress();
        }

        return $customer->addresses()->create([
            'label' => $validated['city'] ?? null,
            'address_line' => $validated['address_line'],
            'city' => $validated['city'] ?? null,
            'pincode' => $validated['pincode'] ?? null,
            'is_default' => $customer->addresses()->doesntExist(),
        ]);
    }

    private function sourceFor(User $user): OrderSource
    {
        return match ($user->role) {
            Role::CUSTOMER => OrderSource::CUSTOMER,
            Role::BRANCH => OrderSource::WALK_IN,
            Role::ADMIN => OrderSource::ADMIN,
            // Super Admin acting on behalf of a client.
            default => OrderSource::ADMIN,
        };
    }

    private function defaultFulfilment(?CustomerAddress $address): FulfilmentType
    {
        return $address === null ? FulfilmentType::CUSTOMER_PICKUP : FulfilmentType::DELIVERY;
    }

    private function customerIdFor(User $user): ?int
    {
        return $this->customerFor($user)?->id;
    }

    /**
     * The customer record behind a signed-in customer account, if there is
     * one. The tenant scope keeps this to the signed-in user's own laundry.
     */
    private function customerFor(User $user): ?Customer
    {
        return Customer::query()
            ->where('mobile', $user->mobile)
            ->first();
    }

    /**
     * @param  array<int, array{item_id: int}>  $items
     */
    private function assertItemsBelongToOrder(Order $order, array $items): void
    {
        $ids = array_column($items, 'item_id');
        $owned = $order->items()->whereIn('id', $ids)->count();

        if ($owned !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'items' => ['One or more lines do not belong to this order.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        // Strict mode forbids lazy loading, so everything the payload
        // touches is loaded up front whichever endpoint called this.
        $order->loadMissing([
            'branch', 'customer', 'address', 'items.service', 'payments',
            'statusHistory.changedBy',
            'pickupAssignment.pickupBoy', 'deliveryAssignment.deliveryBoy',
        ]);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_source' => $order->order_source->value,
            'order_status' => $order->order_status->value,
            'order_status_label' => $order->order_status->label(),
            'fulfilment_type' => $order->fulfilment_type->value,
            'payment_status' => $order->payment_status->value,
            'approx_weight' => $order->approx_weight,
            'actual_weight' => $order->actual_weight,
            'subtotal' => $order->subtotal,
            'pickup_charge' => $order->pickup_charge,
            'delivery_charge' => $order->delivery_charge,
            'discount' => $order->discount,
            'total_amount' => $order->total_amount,
            'amount_due' => $order->amountDue(),
            'branch' => $order->branch?->only(['id', 'name', 'code']),
            'customer' => $order->customer?->only(['id', 'name', 'mobile']),
            'address' => $order->address === null ? null : [
                'id' => $order->address->id,
                'label' => $order->address->label,
                'one_line' => $order->address->oneLine(),
            ],
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'service' => $item->service?->only(['id', 'name', 'pricing_type']),
                'quantity' => $item->quantity,
                'approx_weight' => $item->approx_weight,
                'actual_weight' => $item->actual_weight,
                'unit_price' => $item->unit_price,
                'total_amount' => $item->total_amount,
            ])->values(),
            'pickup_assignment' => $order->pickupAssignment === null ? null : [
                'id' => $order->pickupAssignment->id,
                'status' => $order->pickupAssignment->status->value,
                'assigned_at' => $order->pickupAssignment->assigned_at?->toIso8601String(),
                'picked_at' => $order->pickupAssignment->picked_at?->toIso8601String(),
                'employee' => $order->pickupAssignment->pickupBoy?->only(['id', 'name', 'mobile']),
            ],
            'delivery_assignment' => $order->deliveryAssignment === null ? null : [
                'id' => $order->deliveryAssignment->id,
                'status' => $order->deliveryAssignment->status->value,
                'assigned_at' => $order->deliveryAssignment->assigned_at?->toIso8601String(),
                'delivered_at' => $order->deliveryAssignment->delivered_at?->toIso8601String(),
                'employee' => $order->deliveryAssignment->deliveryBoy?->only(['id', 'name', 'mobile']),
            ],
            'payments' => $order->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'method' => $payment->method->value,
                'status' => $payment->status->value,
                'reference_no' => $payment->reference_no,
                'collected_at' => $payment->collected_at?->toIso8601String(),
            ])->values(),
            'status_history' => $order->statusHistory->map(fn ($history) => [
                'from' => $history->old_status?->value,
                'to' => $history->new_status->value,
                'label' => $history->new_status->label(),
                'note' => $history->note,
                'changed_by' => $history->changedBy?->name,
                'at' => $history->created_at?->toIso8601String(),
            ])->values(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
