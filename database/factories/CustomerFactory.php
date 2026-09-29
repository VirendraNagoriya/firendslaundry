<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->name(),
            'mobile' => '+91'.$this->faker->unique()->numerify('##########'),
            'status' => 'ACTIVE',
        ];
    }

    public function forBusiness(Business $business): static
    {
        return $this->state(fn () => ['business_id' => $business->id]);
    }
}
