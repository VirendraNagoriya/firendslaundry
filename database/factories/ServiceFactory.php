<?php

namespace Database\Factories;

use App\Enums\PricingType;
use App\Models\Business;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'pricing_type' => PricingType::KG,
            'rate' => $this->faker->randomFloat(2, 40, 250),
            'description' => $this->faker->optional()->sentence(4),
            'status' => 'ACTIVE',
        ];
    }

    public function perKg(): static
    {
        return $this->state(fn () => ['pricing_type' => PricingType::KG]);
    }

    public function perPiece(): static
    {
        return $this->state(fn () => ['pricing_type' => PricingType::PIECE]);
    }
}
