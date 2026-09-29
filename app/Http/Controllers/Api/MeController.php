<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    /**
     * Everything the frontend needs to render the right shell for the
     * signed-in role.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'mobile' => $user->mobile,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'status' => $user->status,
            'business_id' => $user->business_id,
            'branch_id' => $user->branch_id,
            'business' => $user->business?->only([
                'id', 'business_name', 'owner_name', 'branch_limit',
                'pickup_charge', 'delivery_charge', 'status',
            ]),
            'branch' => $user->branch?->only(['id', 'name', 'code']),
            'regions' => $user->isPickupBoy() || $user->isDeliveryBoy()
                ? $user->regions->pluck('name')
                : [],
        ];

        if ($user->isCustomer()) {
            $customer = $user->business_id === null ? null : \App\Models\Customer::query()
                ->withoutGlobalScopes()
                ->where('business_id', $user->business_id)
                ->where('mobile', $user->mobile)
                ->first();

            $payload['customer'] = $customer?->only(['id', 'name', 'mobile']);
        }

        return $this->ok($payload);
    }
}
