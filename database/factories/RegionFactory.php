<?php

namespace Database\Factories;

use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    protected $model = Region::class;

    public function definition(): array
    {
        return [
            'business_id' => \App\Models\Business::factory(),
            'name' => ucfirst($this->faker->unique()->citySuffix()),
            'status' => 'ACTIVE',
        ];
    }
}
