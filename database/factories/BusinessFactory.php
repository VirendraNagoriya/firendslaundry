<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        return [
            'business_name' => ucfirst($this->faker->unique()->company()).' Laundry',
            'owner_name' => $this->faker->name(),
            'mobile' => '+91'.$this->faker->unique()->numerify('##########'),
            'branch_limit' => 5,
            'status' => 'ACTIVE',
            'pickup_charge' => 50,
            'delivery_charge' => 40,
            'email' => $this->faker->unique()->safeEmail(),
            'address' => $this->faker->address(),
            'working_hours_start' => '08:00:00',
            'working_hours_end' => '21:00:00',
        ];
    }

    public function withBranchLimit(int $limit): static
    {
        return $this->state(fn () => ['branch_limit' => $limit]);
    }
}
