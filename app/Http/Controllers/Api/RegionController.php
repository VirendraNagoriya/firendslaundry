<?php

namespace App\Http\Controllers\Api;

use App\Models\Region;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegionController extends ApiController
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Field staff only see the zones they are mapped to.
        $regions = Region::query()
            ->withCount('employees')
            ->when(
                $user->isPickupBoy() || $user->isDeliveryBoy(),
                fn ($q) => $q->whereHas('employees', fn ($sub) => $sub->where('users.id', $user->id))
            )
            ->orderBy('name')
            ->get()
            ->map(fn (Region $region) => [
                'id' => $region->id,
                'name' => $region->name,
                'status' => $region->status,
                'employees_count' => $region->employees_count,
            ]);

        return $this->ok($regions);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->business_id === null) {
            return $this->fail('Only a client Admin can manage regions.', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $region = Region::create([
            'business_id' => $user->business_id,
            'name' => $validated['name'],
            'status' => 'ACTIVE',
        ]);

        $this->audit->created($region, ['name' => $region->name]);

        return $this->ok(
            $region->only(['id', 'name', 'status']),
            'Region created.',
            201
        );
    }

    public function update(Request $request, Region $region): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $before = $region->only(['name', 'status']);
        $region->update($validated);

        $this->audit->updated($region, $validated, $before);

        return $this->ok($region->only(['id', 'name', 'status']), 'Region updated.');
    }
}
