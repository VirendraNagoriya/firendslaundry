<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use App\Services\AuditService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends ApiController
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $employees = User::query()
            ->where('role', '!=', Role::SUPER_ADMIN->value)
            ->where(function ($q) use ($user) {
                $q->where('business_id', $user->business_id)
                    ->where(function ($inner) use ($user) {
                        // Admins see all staff; a branch user sees only
                        // their own branch (and unassigned staff).
                        $inner->where('role', Role::ADMIN->value);

                        if ($user->branch_id !== null) {
                            $inner->orWhere('branch_id', $user->branch_id);
                        }
                    });
            })
            ->with('branch:id,name,code', 'regions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $employee) => $this->present($employee));

        return $this->ok($employees);
    }

    /**
     * Create a branch, pickup or delivery account.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:25'],
            'role' => ['required', Rule::in([
                Role::BRANCH->value,
                Role::PICKUP_BOY->value,
                Role::DELIVERY_BOY->value,
            ])],
            'branch_id' => ['nullable', 'integer'],
            'region_ids' => ['nullable', 'array'],
            'region_ids.*' => ['integer'],
        ]);

        if ($user->business_id === null) {
            return $this->fail('Only a client Admin can add employees.', 403);
        }

        $mobile = PhoneNumber::tryParse(
            $validated['mobile'],
            (string) config('sms.default_country_code')
        )?->e164;

        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => ['Enter a valid mobile number.'],
            ]);
        }

        $branch = $this->resolveBranch($validated['branch_id'] ?? null, $user);

        $employee = DB::transaction(function () use ($validated, $mobile, $user, $branch) {
            $existing = User::query()
                ->where('business_id', $user->business_id)
                ->where('mobile', $mobile)
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'mobile' => ['This number already belongs to an account in your business.'],
                ]);
            }

            $employee = User::create([
                'business_id' => $user->business_id,
                'branch_id' => $branch?->id,
                'name' => $validated['name'],
                'mobile' => $mobile,
                'role' => Role::from($validated['role']),
                'status' => 'ACTIVE',
            ]);

            $this->syncRegions($employee, $validated['region_ids'] ?? []);

            return $employee;
        });

        $this->audit->created($employee, [
            'name' => $employee->name,
            'role' => $employee->role->value,
            'branch_id' => $employee->branch_id,
        ]);

        return $this->ok($this->present($employee->load(['branch', 'regions'])), 'Employee created.', 201);
    }

    /**
     * Activate or deactivate an account. Deactivation keeps the record so
     * historical assignments still resolve.
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        $this->assertSameBusiness($user, $actor);

        if ($user->id === $actor->id) {
            return $this->fail('You cannot change the status of your own account.', 422);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $before = ['status' => $user->status];
        $user->forceFill(['status' => $validated['status']])->save();

        $this->audit->updated($user, ['status' => $validated['status']], $before);

        return $this->ok($this->present($user->load(['branch', 'regions'])), 'Employee updated.');
    }

    /**
     * Deactivation instead of deletion, per spec section 21.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        return $this->updateStatus(
            $request->merge(['status' => 'INACTIVE']),
            $user
        );
    }

    private function resolveBranch(?int $branchId, User $actor): ?Branch
    {
        if ($branchId === null) {
            return $actor->branch_id === null ? null : Branch::find($actor->branch_id);
        }

        $branch = Branch::findOrFail($branchId);

        if ($branch->business_id !== $actor->business_id) {
            throw ValidationException::withMessages([
                'branch_id' => ['That branch belongs to a different client.'],
            ]);
        }

        return $branch;
    }

    /**
     * @param  array<int, int>  $regionIds
     */
    private function syncRegions(User $employee, array $regionIds): void
    {
        if ($regionIds === []) {
            $employee->regions()->sync([]);

            return;
        }

        $regions = Region::query()
            ->whereIn('id', $regionIds)
            ->where('business_id', $employee->business_id)
            ->pluck('id')
            ->all();

        $employee->regions()->sync($regions);
    }

    private function assertSameBusiness(User $target, User $actor): void
    {
        if ($target->business_id !== $actor->business_id) {
            abort(403, 'That employee belongs to a different client.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'mobile' => $employee->mobile,
            'role' => $employee->role->value,
            'role_label' => $employee->role->label(),
            'status' => $employee->status,
            'branch' => $employee->branch?->only(['id', 'name', 'code']),
            'regions' => $employee->regions->map(fn (Region $region) => [
                'id' => $region->id,
                'name' => $region->name,
            ])->values(),
        ];
    }
}
