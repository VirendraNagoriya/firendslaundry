<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::SUPER_ADMIN, Role::ADMIN, Role::BRANCH], true);
    }

    public function view(User $user, Customer $customer): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->business_id !== $customer->business_id) {
            return false;
        }

        // A customer may see their own profile.
        if ($user->isCustomer()) {
            return $user->mobile === $customer->mobile;
        }

        return in_array($user->role, [Role::ADMIN, Role::BRANCH], true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [Role::ADMIN, Role::BRANCH], true);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer) && in_array($user->role, [
            Role::SUPER_ADMIN,
            Role::ADMIN,
            Role::BRANCH,
        ], true);
    }

    /**
     * Deactivation instead of deletion, so historic orders keep their
     * customer reference (spec section 21).
     */
    public function deactivate(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer) && in_array($user->role, [
            Role::SUPER_ADMIN,
            Role::ADMIN,
        ], true);
    }
}
