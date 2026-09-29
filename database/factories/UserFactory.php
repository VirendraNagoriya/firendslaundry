<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Business;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'branch_id' => null,
            'name' => $this->faker->name(),
            'mobile' => '+91'.$this->faker->unique()->numerify('##########'),
            'role' => Role::CUSTOMER,
            'status' => 'ACTIVE',
            'last_login_at' => null,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'business_id' => null,
            'branch_id' => null,
            'role' => Role::SUPER_ADMIN,
        ]);
    }

    public function admin(Business|Branch|null $context = null): static
    {
        return $this->state(fn () => [
            'business_id' => $context instanceof Business ? $context->id : Business::factory(),
            'role' => Role::ADMIN,
        ]);
    }

    public function branchUser(Business|Branch|null $context = null): static
    {
        return $this->employee(Role::BRANCH, $context);
    }

    public function pickupBoy(Business|Branch|null $context = null): static
    {
        return $this->employee(Role::PICKUP_BOY, $context);
    }

    public function deliveryBoy(Business|Branch|null $context = null): static
    {
        return $this->employee(Role::DELIVERY_BOY, $context);
    }

    public function customer(Business|null $business = null): static
    {
        return $this->state(fn () => [
            'business_id' => $business?->id ?? Business::factory(),
            'branch_id' => null,
            'role' => Role::CUSTOMER,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'INACTIVE']);
    }

    /**
     * @param  Business|Branch|null  $context  A branch, the business to
     *                                          create a branch in, or null.
     */
    private function employee(Role $role, Business|Branch|null $context): static
    {
        return $this->state(function () use ($role, $context) {
            return [
                'business_id' => $context instanceof Business
                    ? $context->id
                    : ($context?->business_id ?? Business::factory()),
                'branch_id' => $context instanceof Branch
                    ? $context->id
                    : ($context instanceof Business ? Branch::factory()->for($context) : null),
                'role' => $role,
            ];
        });
    }
}
