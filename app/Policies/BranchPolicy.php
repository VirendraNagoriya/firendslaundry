<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->business_id !== null || $user->isSuperAdmin();
    }

    public function view(User $user, Branch $branch): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->business_id !== $branch->business_id) {
            return false;
        }

        // A customer needs to see the branch list and the details of the
        // branch serving their order, so they may read any branch inside
        // their own laundry. They still cannot change one.
        if ($user->isCustomer()) {
            return true;
        }

        return $user->isAdmin() || $branch->id === $user->branch_id;
    }

    /**
     * Creating a branch is also gated by the client's limit, which is
     * enforced in BusinessLimitService inside the creating transaction.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->view($user, $branch) && ($user->isAdmin() || $user->isSuperAdmin());
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->view($user, $branch) && ($user->isAdmin() || $user->isSuperAdmin());
    }
}
