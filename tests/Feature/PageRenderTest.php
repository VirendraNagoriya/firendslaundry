<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PricingType;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pages a person actually looks at.
 *
 * Rendering is the assertion. A dashboard is only proven by being rendered:
 * a wrong type hint, a lazy load under strict mode or a template referring
 * to a relation that does not exist all fail here and nowhere else.
 */
class PageRenderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['supabase.enabled' => false]);

        $this->business = Business::factory()->create();
        $this->branch = Branch::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $this->customer = Customer::factory()->forBusiness($this->business)->create([
            'name' => 'Asha Patil',
        ]);

        $this->service = Service::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Wash and Fold',
            'pricing_type' => PricingType::KG,
            'rate' => 60,
        ]);
    }

    public function test_the_login_page_renders(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Laundry', false);
    }

    public function test_the_verify_page_renders(): void
    {
        $this->withSession(['login.identifier' => '+919876543210'])
            ->get(route('login.verify'))
            ->assertOk();
    }

    /**
     * Every role gets its own dashboard, so every role is rendered here.
     */
    public function test_every_dashboard_renders_for_its_own_role(): void
    {
        $this->makeOrder();

        $users = [
            User::factory()->superAdmin()->create(),
            User::factory()->admin($this->business)->create(),
            User::factory()->branchUser($this->branch)->create(),
            User::factory()->pickupBoy($this->branch)->create(),
            User::factory()->deliveryBoy($this->branch)->create(),
            User::factory()->customer($this->business)->create([
                'mobile' => $this->customer->mobile,
            ]),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk();
        }
    }

    public function test_the_dashboard_survives_a_nonsense_date_range(): void
    {
        $this->actingAs(User::factory()->admin($this->business)->create());

        // A from date after the to date, and a value that is not a date at
        // all, must not blow up the page.
        $this->get(route('dashboard', ['from' => '2026-12-31', 'to' => '2026-01-01']))
            ->assertOk();

        $this->get(route('dashboard', ['from' => 'not-a-date']))
            ->assertOk();
    }

    public function test_the_order_list_and_detail_render(): void
    {
        $order = $this->makeOrder();

        $admin = User::factory()->admin($this->business)->create();

        $this->actingAs($admin)->get(route('orders.index'))->assertOk();
        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk();
    }

    public function test_a_customer_sees_their_own_order_and_not_another_persons(): void
    {
        $theirs = $this->makeOrder();

        // A different person at the same laundry, on purpose.
        $stranger = Customer::factory()->forBusiness($this->business)->create([
            'name' => 'Imran Shaikh',
        ]);

        $someoneElses = $this->makeOrder(['customer_id' => $stranger->id]);

        $user = User::factory()->customer($this->business)->create([
            'mobile' => $this->customer->mobile,
        ]);

        $this->actingAs($user)->get(route('orders.show', $theirs))->assertOk();

        // Same laundry, different person: the order is visible to the
        // tenant scope but the policy refuses it, so nothing is shown.
        $this->actingAs($user)
            ->get(route('orders.show', $someoneElses))
            ->assertForbidden();
    }

    public function test_one_laundry_cannot_read_an_order_from_another(): void
    {
        $order = $this->makeOrder();

        $rival = Business::factory()->create();

        $this->actingAs(User::factory()->admin($rival)->create())
            ->get(route('orders.show', $order))
            ->assertNotFound();
    }

    /**
     * A customer has no status button, so posting one must be refused.
     */
    public function test_a_customer_cannot_push_an_order_forward(): void
    {
        $order = $this->makeOrder(['order_status' => OrderStatus::RECEIVED_AT_BRANCH->value]);
        $user = User::factory()->customer($this->business)->create([
            'mobile' => $this->customer->mobile,
        ]);

        $this->actingAs($user)
            ->patch(route('orders.status', $order), [
                'status' => OrderStatus::WEIGHED->value,
            ])
            ->assertForbidden();
    }

    private function makeOrder(array $overrides = []): Order
    {
        $order = Order::factory()->forBusiness($this->business, $this->branch)->create(array_merge([
            'customer_id' => $this->customer->id,
            'order_status' => OrderStatus::PROCESSING->value,
            'order_number' => 'LD'.random_int(100000, 999999),
            'total_amount' => 240,
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'service_id' => $this->service->id,
            'approx_weight' => 4.000,
            'unit_price' => 60,
            'total_amount' => 240,
        ]);

        Payment::create([
            'business_id' => $this->business->id,
            'order_id' => $order->id,
            'amount' => 240,
            'method' => PaymentMethod::CASH,
            'status' => PaymentStatus::PAID,
            'collected_at' => now(),
        ]);

        return $order->load(['branch', 'customer', 'items.service', 'payments']);
    }
}
