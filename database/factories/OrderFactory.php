<?php

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $business = Business::factory();
        $branch = Branch::factory()->for($business);

        return [
            'business_id' => $business,
            'branch_id' => $branch,
            'customer_id' => null,
            'order_number' => strtoupper($this->faker->unique()->bothify('ORD-#####')),
            'order_source' => OrderSource::CUSTOMER,
            'order_status' => OrderStatus::ORDER_CREATED,
            'fulfilment_type' => \App\Enums\FulfilmentType::DELIVERY,
            'subtotal' => 0,
            'discount' => 0,
            'pickup_charge' => 50,
            'delivery_charge' => 40,
            'total_amount' => 0,
            'payment_status' => PaymentStatus::PENDING,
        ];
    }

    public function forBusiness(Business $business, ?Branch $branch = null): static
    {
        return $this->state(fn () => [
            'business_id' => $business->id,
            // A factory here is resolved to the id of the model it
            // creates, so the branch is only built when one is needed.
            'branch_id' => $branch?->id ?? Branch::factory()->for($business),
        ]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn () => ['order_status' => $status]);
    }

    public function walkIn(): static
    {
        return $this->state(fn () => ['order_source' => OrderSource::WALK_IN]);
    }

    public function forCustomer(Customer $customer, ?Branch $branch = null): static
    {
        return $this->state(fn () => [
            'business_id' => $customer->business_id,
            'branch_id' => $branch?->id ?? Branch::factory()->for($customer->business),
            'customer_id' => $customer->id,
        ]);
    }
}
