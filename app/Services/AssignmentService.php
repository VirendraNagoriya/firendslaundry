<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Region;
use App\Models\User;
use App\Models\DeliveryAssignment;
use App\Models\PickupAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assigns pickup and delivery staff to orders.
 *
 * Assignment is region-aware: an employee may only be given work for the
 * regions they are mapped to (spec sections 17-18). An employee with no
 * regions mapped is treated as covering the whole business, which is what
 * a small single-branch laundry actually wants.
 */
class AssignmentService
{
    public function __construct(
        private readonly OrderStatusService $status,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Assign a pickup boy to an order.
     */
    public function assignPickup(Order $order, User $pickupBoy, ?User $actor = null, ?string $note = null): PickupAssignment
    {
        $this->assertEmployeeUsable($pickupBoy, Role::PICKUP_BOY);
        $this->assertSameBusiness($order, $pickupBoy);
        $this->assertOrderInStatus($order, [OrderStatus::ORDER_CREATED, OrderStatus::PICKUP_ASSIGNED]);

        $assignment = DB::transaction(function () use ($order, $pickupBoy, $actor, $note) {
            // Retire any earlier assignment rather than deleting it: the
            // trail of who was going where is part of the record.
            PickupAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', AssignmentStatus::ASSIGNED->value)
                ->update(['status' => AssignmentStatus::CANCELLED->value]);

            $assignment = PickupAssignment::create([
                'order_id' => $order->id,
                'pickup_boy_id' => $pickupBoy->id,
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
                'status' => AssignmentStatus::ASSIGNED,
            ]);

            if ($order->order_status === OrderStatus::ORDER_CREATED) {
                $this->status->transition($order, OrderStatus::PICKUP_ASSIGNED, $actor, $note);
            }

            return $assignment;
        });

        $this->audit->created($assignment, [
            'order_number' => $order->order_number,
            'pickup_boy' => $pickupBoy->name,
        ]);

        return $assignment;
    }

    /**
     * Assign a delivery boy to a ready order.
     */
    public function assignDelivery(Order $order, User $deliveryBoy, ?User $actor = null, ?string $note = null): DeliveryAssignment
    {
        $this->assertEmployeeUsable($deliveryBoy, Role::DELIVERY_BOY);
        $this->assertSameBusiness($order, $deliveryBoy);
        $this->assertOrderInStatus($order, [
            OrderStatus::READY,
            OrderStatus::DELIVERY_ASSIGNED,
        ]);

        $assignment = DB::transaction(function () use ($order, $deliveryBoy, $actor, $note) {
            DeliveryAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', AssignmentStatus::ASSIGNED->value)
                ->update(['status' => AssignmentStatus::CANCELLED->value]);

            $assignment = DeliveryAssignment::create([
                'order_id' => $order->id,
                'delivery_boy_id' => $deliveryBoy->id,
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
                'status' => AssignmentStatus::ASSIGNED,
            ]);

            if ($order->order_status === OrderStatus::READY) {
                $this->status->transition($order, OrderStatus::DELIVERY_ASSIGNED, $actor, $note);
            }

            return $assignment;
        });

        $this->audit->created($assignment, [
            'order_number' => $order->order_number,
            'delivery_boy' => $deliveryBoy->name,
        ]);

        return $assignment;
    }

    /**
     * A pickup boy confirms they have collected the goods.
     */
    public function markPickedUp(Order $order, User $pickupBoy, ?string $note = null): PickupAssignment
    {
        $this->assertOrderInStatus($order, [OrderStatus::PICKUP_ASSIGNED, OrderStatus::ORDER_CREATED]);

        $assignment = DB::transaction(function () use ($order, $pickupBoy, $note) {
            $assignment = PickupAssignment::query()
                ->where('order_id', $order->id)
                ->where('pickup_boy_id', $pickupBoy->id)
                ->where('status', AssignmentStatus::ASSIGNED->value)
                ->latest('id')
                ->first();

            if ($assignment === null) {
                throw new AssignmentException('You are not assigned to pick up this order.');
            }

            $assignment->forceFill([
                'status' => AssignmentStatus::IN_PROGRESS,
                'picked_at' => now(),
            ])->save();

            $this->status->transition($order, OrderStatus::PICKED_UP, $pickupBoy, $note);

            return $assignment;
        });

        $this->audit->updated($assignment, ['status' => AssignmentStatus::IN_PROGRESS->value]);

        return $assignment;
    }

    /**
     * A delivery boy starts the last mile.
     */
    public function markOutForDelivery(Order $order, User $deliveryBoy, ?string $note = null): DeliveryAssignment
    {
        $this->assertOrderInStatus($order, [OrderStatus::DELIVERY_ASSIGNED]);

        $assignment = $this->openDeliveryAssignment($order, $deliveryBoy);

        DB::transaction(function () use ($order, $deliveryBoy, $assignment, $note) {
            $assignment->forceFill(['status' => AssignmentStatus::IN_PROGRESS])->save();
            $this->status->transition($order, OrderStatus::OUT_FOR_DELIVERY, $deliveryBoy, $note);
        });

        $this->audit->updated($assignment, ['status' => AssignmentStatus::IN_PROGRESS->value]);

        return $assignment;
    }

    /**
     * A delivery boy confirms hand-over.
     */
    public function markDelivered(Order $order, User $deliveryBoy, ?string $note = null): DeliveryAssignment
    {
        $this->assertOrderInStatus($order, [OrderStatus::OUT_FOR_DELIVERY]);

        $assignment = $this->openDeliveryAssignment($order, $deliveryBoy);

        DB::transaction(function () use ($order, $deliveryBoy, $assignment, $note) {
            $assignment->forceFill([
                'status' => AssignmentStatus::COMPLETED,
                'delivered_at' => now(),
            ])->save();

            $this->status->transition($order, OrderStatus::DELIVERED, $deliveryBoy, $note);
        });

        $this->audit->updated($assignment, [
            'status' => AssignmentStatus::COMPLETED->value,
            'delivered_at' => now()->toDateTimeString(),
        ]);

        return $assignment;
    }

    /**
     * Employees who could reasonably take this order, nearest match first.
     *
     * @return Collection<int, User>
     */
    public function suggestCandidates(Order $order, Role $role): Collection
    {
        return User::query()
            ->where('role', $role->value)
            ->where('status', 'ACTIVE')
            ->when($order->business_id, fn ($q) => $q->where('business_id', $order->business_id))
            ->when($order->branch_id, fn ($q) => $q->where(fn ($sub) => $sub
                ->where('branch_id', $order->branch_id)
                ->orWhereNull('branch_id')))
            ->with('regions')
            ->get()
            ->filter(fn (User $employee) => $this->servesOrderRegions($employee, $order))
            ->sortByDesc(fn (User $employee) => $this->loadCount($employee))
            ->values();
    }

    /**
     * An employee may take an order when they cover every region the order
     * touches, or when they have no region restriction at all.
     */
    public function servesOrderRegions(User $employee, Order $order): bool
    {
        $employeeRegions = $employee->regions->pluck('id');

        if ($employeeRegions->isEmpty()) {
            return true;
        }

        $orderRegionIds = $this->orderRegionIds($order);

        if ($orderRegionIds->isEmpty()) {
            return true;
        }

        return $orderRegionIds->diff($employeeRegions)->isEmpty();
    }

    /**
     * Regions the order's delivery address falls into, matched on the
     * region's name appearing in the address text.
     *
     * A small, dependency-free stand-in for geocoding: the spec treats
     * region mapping as a business configuration, and a city laundry
     * maps addresses to named zones well enough this way.
     *
     * @return Collection<int, int>
     */
    private function orderRegionIds(Order $order): Collection
    {
        $address = $order->address;

        if ($address === null) {
            return collect();
        }

        $haystack = strtolower($address->oneLine());

        if (trim($haystack) === '') {
            return collect();
        }

        return Region::query()
            ->where('business_id', $order->business_id)
            ->pluck('name', 'id')
            ->filter(fn (string $name) => str_contains($haystack, strtolower(trim($name))))
            ->keys();
    }

    private function loadCount(User $employee): int
    {
        $open = $employee->isPickupBoy()
            ? PickupAssignment::query()
                ->where('pickup_boy_id', $employee->id)
                ->whereIn('status', [AssignmentStatus::ASSIGNED->value, AssignmentStatus::IN_PROGRESS->value])
                ->count()
            : DeliveryAssignment::query()
                ->where('delivery_boy_id', $employee->id)
                ->whereIn('status', [AssignmentStatus::ASSIGNED->value, AssignmentStatus::IN_PROGRESS->value])
                ->count();

        // Fewer open jobs first: it spreads the work evenly.
        return -$open;
    }

    private function openDeliveryAssignment(Order $order, User $deliveryBoy): DeliveryAssignment
    {
        $assignment = DeliveryAssignment::query()
            ->where('order_id', $order->id)
            ->where('delivery_boy_id', $deliveryBoy->id)
            ->whereIn('status', [
                AssignmentStatus::ASSIGNED->value,
                AssignmentStatus::IN_PROGRESS->value,
            ])
            ->latest('id')
            ->first();

        if ($assignment === null) {
            throw new AssignmentException('You are not assigned to deliver this order.');
        }

        return $assignment;
    }

    private function assertEmployeeUsable(User $employee, Role $expected): void
    {
        if ($employee->role !== $expected) {
            throw new AssignmentException(
                "This user is not a {$expected->label()}."
            );
        }

        if (! $employee->isActive()) {
            throw new AssignmentException(
                "{$employee->name} is deactivated and cannot take new work."
            );
        }
    }

    private function assertSameBusiness(Order $order, User $employee): void
    {
        if ($employee->business_id !== $order->business_id) {
            throw new AssignmentException(
                'That employee belongs to a different client and cannot be assigned this order.'
            );
        }
    }

    /**
     * @param  list<OrderStatus>  $allowed
     */
    private function assertOrderInStatus(Order $order, array $allowed): void
    {
        if (! in_array($order->order_status, $allowed, true)) {
            throw new AssignmentException(
                "This action is not available while the order is {$order->order_status->label()}."
            );
        }
    }
}
