<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * The permission matrix from spec section 3, expressed as data so it
     * can be read against the table in the document.
     *
     * "view" is the coarse gate. A customer's true access is narrower
     * than a plain true/false: view() below only lets them open their own
     * orders, and a pickup or delivery boy only the ones assigned to them.
     *
     * @var array<string, array<string, bool>>
     */
    private const MATRIX = [
        'view' => [
            'SUPER_ADMIN' => true,
            'ADMIN' => true,
            'BRANCH' => true,
            'PICKUP_BOY' => true,
            'DELIVERY_BOY' => true,
            'CUSTOMER' => true,
        ],
        'create' => [
            'SUPER_ADMIN' => true,
            'ADMIN' => true,
            'BRANCH' => true,
            'PICKUP_BOY' => false,
            'DELIVERY_BOY' => false,
            // A customer places their own order; the controller forces the
            // customer to themselves, so this cannot be used to order for
            // somebody else.
            'CUSTOMER' => true,
        ],
        'changeStatus' => [
            'SUPER_ADMIN' => true,
            'ADMIN' => true,
            'BRANCH' => true,
            'PICKUP_BOY' => false,
            'DELIVERY_BOY' => false,
            'CUSTOMER' => false,
        ],
        'recordWeight' => [
            'SUPER_ADMIN' => true,
            'ADMIN' => true,
            'BRANCH' => true,
            'PICKUP_BOY' => true,
            'DELIVERY_BOY' => false,
            'CUSTOMER' => false,
        ],
        'assignPickup' => [
            'SUPER_ADMIN' => true,
            'ADMIN' => true,
            'BRANCH' => true,
            'PICKUP_BOY' => false,
            'DELIVERY_BOY' => false,
            'CUSTOMER' => false,
        ],
        'assignDelivery' => [
            'SUPER_ADMIN' => true,
            'ADMIN' => true,
            'BRANCH' => true,
            'PICKUP_BOY' => false,
            'DELIVERY_BOY' => false,
            'CUSTOMER' => false,
        ],
        'recordPayment' => [
            'SUPER_ADMIN' => true,
            'ADMIN' => true,
            'BRANCH' => true,
            'PICKUP_BOY' => false,
            'DELIVERY_BOY' => true,
            'CUSTOMER' => false,
        ],
        'cancel' => [
            'SUPER_ADMIN' => true,
            'ADMIN' => true,
            'BRANCH' => false,
            'PICKUP_BOY' => false,
            'DELIVERY_BOY' => false,
            'CUSTOMER' => false,
        ],
    ];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        if (! $this->allowed($user, 'view')) {
            return false;
        }

        // Super Admin is platform-level and may inspect any order.
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Cross-tenant access is denied before anything else is considered.
        if ($user->business_id !== $order->business_id) {
            return false;
        }

        return match ($user->role) {
            Role::ADMIN => true,
            Role::BRANCH => $order->branch_id === $user->branch_id,
            Role::CUSTOMER => $order->customer_id === $this->customerIdFor($user),
            Role::PICKUP_BOY, Role::DELIVERY_BOY => $this->isAssignedTo($user, $order),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'create');
    }

    public function changeStatus(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $this->allowed($user, 'changeStatus');
    }

    public function recordWeight(User $user, Order $order): bool
    {
        if (! $this->allowed($user, 'recordWeight')) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        if ($user->isBranch()) {
            return $order->branch_id === $user->branch_id;
        }

        return $user->isPickupBoy() && $this->isAssignedTo($user, $order);
    }

    public function assignPickup(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $this->allowed($user, 'assignPickup');
    }

    public function assignDelivery(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $this->allowed($user, 'assignDelivery');
    }

    public function recordPayment(User $user, Order $order): bool
    {
        if (! $this->allowed($user, 'recordPayment')) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        if ($user->isBranch()) {
            return $order->branch_id === $user->branch_id;
        }

        return $user->isDeliveryBoy() && $this->isAssignedTo($user, $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $this->allowed($user, 'cancel');
    }

    /**
     * Only platform or business leadership may rewrite history.
     */
    public function editHistory(User $user, Order $order): bool
    {
        return $user->isSuperAdmin()
            || ($user->isAdmin() && $user->business_id === $order->business_id);
    }

    private function allowed(User $user, string $ability): bool
    {
        return self::MATRIX[$ability][$user->role->value] ?? false;
    }

    private function isAssignedTo(User $user, Order $order): bool
    {
        if ($user->isPickupBoy()) {
            return $order->pickupAssignment?->pickup_boy_id === $user->id;
        }

        if ($user->isDeliveryBoy()) {
            return $order->deliveryAssignment?->delivery_boy_id === $user->id;
        }

        return false;
    }

    private function customerIdFor(User $user): ?int
    {
        return $user->business_id === null
            ? null
            : \App\Models\Customer::query()
                ->withoutGlobalScopes()
                ->where('business_id', $user->business_id)
                ->where('mobile', $user->mobile)
                ->value('id');
    }
}
