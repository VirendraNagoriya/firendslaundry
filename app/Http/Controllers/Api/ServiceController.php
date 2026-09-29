<?php

namespace App\Http\Controllers\Api;

use App\Enums\PricingType;
use App\Models\Service;
use App\Services\AuditService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends ApiController
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $services = Service::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'pricing_type' => $service->pricing_type->value,
                'pricing_type_label' => $service->pricing_type->label(),
                'rate' => $service->rate,
                'unit' => $service->pricing_type === PricingType::KG ? 'per kg' : 'per piece',
                'description' => $service->description,
                'status' => $service->status,
            ]);

        return $this->ok($services);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->business_id === null) {
            return $this->fail('Only a client Admin can manage the price list.', 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'pricing_type' => ['required', Rule::in(PricingType::values())],
            'rate' => ['required', 'numeric', 'min:0', 'max:99999'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $service = Service::create([
            'business_id' => $user->business_id,
            'name' => $validated['name'],
            'pricing_type' => PricingType::from($validated['pricing_type']),
            'rate' => $validated['rate'],
            'description' => $validated['description'] ?? null,
            'status' => 'ACTIVE',
        ]);

        $this->audit->created($service, [
            'name' => $service->name,
            'rate' => $service->rate,
        ]);

        return $this->ok($this->present($service), 'Service created.', 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'rate' => ['sometimes', 'numeric', 'min:0', 'max:99999'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $before = $service->only(['name', 'rate', 'description', 'status']);
        $service->update($validated);

        $this->audit->updated($service, $validated, $before);

        return $this->ok($this->present($service->fresh()), 'Service updated.');
    }

    /**
     * Quote one line, so the order screens can show a live price as the
     * customer types an estimate.
     */
    public function quote(Request $request, Service $service): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1'],
            'approx_weight' => ['nullable', 'numeric', 'min:0'],
        ]);

        $amount = $this->pricing->priceLine(
            $service,
            $validated['quantity'] ?? null,
            $validated['approx_weight'] ?? null,
        );

        return $this->ok([
            'service_id' => $service->id,
            'pricing_type' => $service->pricing_type->value,
            'amount' => $amount,
            'is_estimate' => $service->isPricedByWeight(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'pricing_type' => $service->pricing_type->value,
            'pricing_type_label' => $service->pricing_type->label(),
            'rate' => $service->rate,
            'description' => $service->description,
            'status' => $service->status,
        ];
    }
}
