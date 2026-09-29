<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\AuditService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends ApiController
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->with('addresses')
            ->withCount('orders')
            ->when($request->filled('q'), fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('name', 'like', '%'.$request->string('q').'%')
                    ->orWhere('mobile', 'like', '%'.$request->string('q').'%');
            }))
            ->orderBy('name')
            ->paginate(min(100, (int) $request->integer('per_page', 25)));

        return $this->ok($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('create', Customer::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:25'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:10'],
        ]);

        $mobile = PhoneNumber::tryParse(
            $validated['mobile'],
            (string) config('sms.default_country_code')
        )?->e164;

        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => ['Enter a valid mobile number.'],
            ]);
        }

        // A branch may only add customers to its own business, which the
        // tenant scope already guarantees for business_id.
        $existing = Customer::query()->where('mobile', $mobile)->first();

        if ($existing !== null) {
            return $this->ok(
                $this->present($existing),
                'This customer already exists — opening their profile.',
                200
            );
        }

        $customer = Customer::create([
            'name' => $validated['name'],
            'mobile' => $mobile,
            'status' => 'ACTIVE',
        ]);

        if (! empty($validated['address_line'])) {
            $customer->addresses()->create([
                'label' => $validated['city'] ?? null,
                'address_line' => $validated['address_line'],
                'city' => $validated['city'] ?? null,
                'pincode' => $validated['pincode'] ?? null,
                'is_default' => true,
            ]);
        }

        $this->audit->created($customer, [
            'name' => $customer->name,
            'mobile' => $customer->mobile,
        ]);

        return $this->ok(
            $this->present($customer->load('addresses')),
            'Customer created.',
            201
        );
    }

    /**
     * Deactivate rather than delete, so historic orders keep resolving.
     */
    public function updateStatus(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        $before = ['status' => $customer->status];
        $customer->forceFill(['status' => $validated['status']])->save();

        $this->audit->updated($customer, ['status' => $validated['status']], $before);

        return $this->ok($this->present($customer), 'Customer updated.');
    }

    public function storeAddress(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'address_line' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:10'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        if (($validated['is_default'] ?? false) === true) {
            $customer->addresses()->update(['is_default' => false]);
        }

        $address = $customer->addresses()->create([
            'label' => $validated['label'] ?? null,
            'address_line' => $validated['address_line'],
            'city' => $validated['city'] ?? null,
            'pincode' => $validated['pincode'] ?? null,
            'is_default' => $validated['is_default'] ?? $customer->addresses()->doesntExist(),
        ]);

        return $this->ok([
            'id' => $address->id,
            'label' => $address->label,
            'one_line' => $address->oneLine(),
            'is_default' => $address->is_default,
        ], 'Address saved.', 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'status' => $customer->status,
            'orders_count' => $customer->orders_count ?? $customer->orders()->count(),
            'addresses' => $customer->relationLoaded('addresses')
                ? $customer->addresses->map(fn (CustomerAddress $address) => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'one_line' => $address->oneLine(),
                    'is_default' => $address->is_default,
                ])->values()
                : [],
        ];
    }
}
