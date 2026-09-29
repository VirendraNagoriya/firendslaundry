<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => 'Branch '.$this->faker->unique()->numberBetween(1, 9999),
            'code' => strtoupper($this->faker->unique()->bothify('BR##')),
            'phone' => '+91'.$this->faker->numerify('##########'),
            'address' => $this->faker->address(),
            'status' => 'ACTIVE',
        ];
    }
}
