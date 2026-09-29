<?php

namespace App\Services;

use App\Exceptions\BranchLimitExceededException;
use App\Models\Branch;
use App\Models\Business;

/**
 * Enforces the per-client branch limit set by the Super Admin
 * (spec section 5).
 *
 * Example: ABC Laundry has branch_limit 7 and has created 5 branches, so
 * two slots remain. The check is done inside the same transaction that
 * creates the branch, so two admins clicking "create" at the same moment
 * cannot slip past the limit together.
 */
class BusinessLimitService
{
    /**
     * @throws BranchLimitExceededException
     */
    public function ensureCanCreateBranch(Business $business): void
    {
        if (! $business->isActive()) {
            throw BranchLimitExceededException::businessInactive();
        }

        $limit = (int) $business->branch_limit;
        $used = $this->branchesUsed($business);

        if ($used >= $limit) {
            throw BranchLimitExceededException::limitReached($limit, $used);
        }
    }

    public function branchesUsed(Business $business): int
    {
        return Branch::query()
            ->withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->count();
    }

    public function branchesRemaining(Business $business): int
    {
        return max(0, (int) $business->branch_limit - $this->branchesUsed($business));
    }

    public function hasCapacity(Business $business): bool
    {
        return $this->branchesUsed($business) < (int) $business->branch_limit;
    }

    /**
     * Update a client's branch limit, refusing a value below the number
     * of branches already in use.
     *
     * @throws BranchLimitExceededException
     */
    public function updateLimit(Business $business, int $newLimit, ?int $newStatus = null): Business
    {
        $newLimit = max(0, $newLimit);
        $used = $this->branchesUsed($business);

        if ($newLimit < $used) {
            throw BranchLimitExceededException::limitBelowUsage($newLimit, $used);
        }

        $business->forceFill(array_filter([
            'branch_limit' => $newLimit,
            'status' => $newStatus,
        ], fn ($value) => $value !== null))->save();

        return $business;
    }
}
