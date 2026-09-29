<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BusinessLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchController extends ApiController
{
    public function __construct(
        private readonly BusinessLimitService $limits,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $branches = Branch::query()
            ->withCount('orders')
            ->when($user->isBranch(), fn ($q) => $q->whereKey($user->branch_id))
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'status' => $branch->status,
                'orders_count' => $branch->orders_count,
            ]);

        $usage = null;

        if ($user->business_id !== null) {
            $business = $user->business;

            $usage = [
                'branch_limit' => $business->branch_limit,
                'branches_used' => $this->limits->branchesUsed($business),
                'branches_remaining' => $this->limits->branchesRemaining($business),
            ];
        }

        return $this->ok(['branches' => $branches, 'branch_limit' => $usage]);
    }

    /**
     * Create a branch, refused once the client's limit is reached.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('create', Branch::class);

        $business = $user->business;

        if ($business === null) {
            return $this->fail('Only a client Admin can create a branch.', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'phone' => ['nullable', 'string', 'max:25'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['code'] = strtoupper($validated['code']);

        // Checked inside the transaction as well, so two concurrent
        // requests cannot both slip past the limit.
        $branch = DB::transaction(function () use ($business, $validated) {
            $this->limits->ensureCanCreateBranch($business);

            return Branch::create([
                'business_id' => $business->id,
                'name' => $validated['name'],
                'code' => $validated['code'],
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'status' => 'ACTIVE',
            ]);
        });

        $this->audit->created($branch, [
            'name' => $branch->name,
            'code' => $branch->code,
        ]);

        return $this->ok([
            'branch' => $branch->only(['id', 'name', 'code', 'phone', 'address', 'status']),
            'branch_limit' => [
                'branch_limit' => $business->fresh()->branch_limit,
                'branches_used' => $this->limits->branchesUsed($business),
                'branches_remaining' => $this->limits->branchesRemaining($business),
            ],
        ], 'Branch created.', 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $this->authorize('update', $branch);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'phone' => ['nullable', 'string', 'max:25'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $before = $branch->only(['name', 'code', 'phone', 'address', 'status']);

        $branch->update($validated);

        $this->audit->updated($branch, $validated, $before);

        return $this->ok($branch->only(['id', 'name', 'code', 'phone', 'address', 'status']), 'Branch updated.');
    }

    /**
     * Employee accounts visible to the signed-in Admin.
     */
    public function staff(Request $request): JsonResponse
    {
        $user = $request->user();

        $staff = User::query()
            ->whereIn('role', array_map(
                fn (Role $role) => $role->value,
                [Role::BRANCH, Role::PICKUP_BOY, Role::DELIVERY_BOY]
            ))
            ->with('branch:id,name,code', 'regions:id,name')
            ->when($user->isBranch(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->orderBy('name')
            ->get()
            ->map(fn (User $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'mobile' => $employee->mobile,
                'role' => $employee->role->value,
                'role_label' => $employee->role->label(),
                'status' => $employee->status,
                'branch' => $employee->branch?->only(['id', 'name', 'code']),
                'regions' => $employee->regions->pluck('name')->values(),
            ]);

        return $this->ok($staff);
    }
}
