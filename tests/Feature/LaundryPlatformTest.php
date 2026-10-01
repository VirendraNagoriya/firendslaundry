<?php

use App\Enums\OrderStatus;
use App\Enums\PricingType;
use App\Enums\Role;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsResult;
use App\Services\Sms\TextBeeDriver;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use App\Services\BusinessLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the security-critical rules in the platform.
 *
 * Covers: tenant isolation (spec section 4), branch limits (section 5),
 * the role matrix (section 3), KG re-pricing (section 13), the order
 * lifecycle (section 14), assignment rules (section 17), payments
 * (section 18) and soft deletion (section 21).
 *
 * Sign-in is covered separately in IdentityAuthTest, because proving who
 * somebody is is a different problem from what they may do once inside.
 */
class LaundryPlatformTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Phone normalisation
    // -----------------------------------------------------------------

    public function test_phone_numbers_normalise_to_e164(): void
    {
        $expected = '+919876543210';

        foreach (['+919876543210', '919876543210', '09876543210', '9876543210', '+91 98765 43210'] as $input) {
            $this->assertSame($expected, PhoneNumber::parse($input)->e164, "Failed for: {$input}");
        }
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->assertNull(PhoneNumber::tryParse('12345'));
        $this->assertNull(PhoneNumber::tryParse('not-a-number'));
    }

    // -----------------------------------------------------------------
    // Tenant isolation: section 4
    // -----------------------------------------------------------------

    public function test_a_tenant_admin_cannot_see_another_tenants_orders(): void
    {
        $abc = Business::factory()->withBranchLimit(5)->create();
        $xyz = Business::factory()->withBranchLimit(5)->create();

        $abcAdmin = User::factory()->admin($abc)->create();
        $xyzAdmin = User::factory()->admin($xyz)->create();

        $abcOrder = \App\Models\Order::factory()->forBusiness($abc)->create();
        $xyzOrder = \App\Models\Order::factory()->forBusiness($xyz)->create();

        $this->actingAs($abcAdmin)
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $abcOrder->id);

        // Fetching the other tenant's order by id must not work. The
        // tenant scope removes the row from the lookup, so the answer is
        // 404 rather than 403: a stranger cannot even learn the id exists.
        $this->actingAs($abcAdmin)
            ->getJson("/api/orders/{$xyzOrder->id}")
            ->assertNotFound();

        $this->actingAs($xyzAdmin)
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $xyzOrder->id);
    }

    public function test_business_id_supplied_by_the_browser_is_ignored(): void
    {
        $abc = Business::factory()->create();
        $xyz = Business::factory()->create();

        $abcAdmin = User::factory()->admin($abc)->create();

        \App\Models\Order::factory()->forBusiness($xyz)->create();

        // The client tries to smuggle in the other tenant's id.
        $this->actingAs($abcAdmin)
            ->getJson('/api/orders?business_id='.$xyz->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    public function test_a_customer_only_sees_their_own_orders(): void
    {
        $business = Business::factory()->create();
        $customer = Customer::factory()->forBusiness($business)->create();

        $mine = \App\Models\Order::factory()->forCustomer($customer)->create();

        $otherCustomer = Customer::factory()->forBusiness($business)->create();
        $theirs = \App\Models\Order::factory()->forCustomer($otherCustomer)->create();

        $user = User::factory()->customer($business)->create(['mobile' => $customer->mobile]);

        $this->actingAs($user)
            ->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $mine->id);

        $this->actingAs($user)
            ->getJson("/api/orders/{$theirs->id}")
            ->assertForbidden();
    }

    public function test_tenant_scope_can_be_pinned_for_super_admin(): void
    {
        $abc = Business::factory()->create();
        $xyz = Business::factory()->create();

        \App\Models\Order::factory()->forBusiness($abc)->create();
        \App\Models\Order::factory()->forBusiness($xyz)->create();

        $count = \App\Models\Order::forBusiness(
            $abc->id,
            fn () => \App\Models\Order::count()
        );

        $this->assertSame(1, $count);
    }

    // -----------------------------------------------------------------
    // Branch limit: section 5
    // -----------------------------------------------------------------

    public function test_branch_creation_is_refused_at_the_limit(): void
    {
        $business = Business::factory()->withBranchLimit(2)->create();
        $admin = User::factory()->admin($business)->create();

        \App\Models\Branch::factory()->count(2)->for($business)->create();

        $response = $this->actingAs($admin)->postJson('/api/branches', [
            'name' => 'Third Branch',
            'code' => 'BR3',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('branch limit', strtolower($response->json('message')));
    }

    public function test_branch_creation_succeeds_below_the_limit(): void
    {
        $business = Business::factory()->withBranchLimit(3)->create();
        $admin = User::factory()->admin($business)->create();

        \App\Models\Branch::factory()->count(2)->for($business)->create();

        $this->actingAs($admin)->postJson('/api/branches', [
            'name' => 'Third Branch',
            'code' => 'BR3',
        ])->assertCreated();

        $this->assertSame(3, $business->branchesUsed());
        $this->assertSame(0, $business->branchesRemaining());
    }

    public function test_branch_limit_of_zero_blocks_the_first_branch(): void
    {
        $business = Business::factory()->withBranchLimit(0)->create();
        $admin = User::factory()->admin($business)->create();

        $this->actingAs($admin)->postJson('/api/branches', [
            'name' => 'Only Branch',
            'code' => 'BR1',
        ])->assertStatus(422);
    }

    public function test_branch_limit_cannot_be_set_below_current_usage(): void
    {
        $business = Business::factory()->withBranchLimit(5)->create();
        \App\Models\Branch::factory()->count(3)->for($business)->create();

        $this->expectException(\App\Exceptions\BranchLimitExceededException::class);

        app(BusinessLimitService::class)->updateLimit($business, 2);
    }

    public function test_branch_limit_can_be_raised_by_super_admin(): void
    {
        $business = Business::factory()->withBranchLimit(1)->create();
        \App\Models\Branch::factory()->for($business)->create();

        $limits = app(BusinessLimitService::class);
        $limits->updateLimit($business, 7);

        $this->assertSame(7, (int) $business->fresh()->branch_limit);
        $this->assertSame(6, $limits->branchesRemaining($business->fresh()));
    }

    // -----------------------------------------------------------------
    // Role matrix: section 3
    // -----------------------------------------------------------------

    public function test_a_pickup_boy_cannot_change_order_status(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create();
        $pickup = User::factory()->pickupBoy($branch)->create();

        $this->actingAs($pickup)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => OrderStatus::PROCESSING->value])
            ->assertForbidden();
    }

    public function test_a_customer_cannot_change_order_status(): void
    {
        $business = Business::factory()->create();
        $customer = Customer::factory()->forBusiness($business)->create();
        $order = \App\Models\Order::factory()->forCustomer($customer)->create();
        $user = User::factory()->customer($business)->create(['mobile' => $customer->mobile]);

        $this->actingAs($user)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => OrderStatus::CANCELLED->value])
            ->assertForbidden();
    }

    public function test_a_pickup_boy_cannot_record_a_payment(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create();
        $pickup = User::factory()->pickupBoy($branch)->create();

        $this->actingAs($pickup)
            ->postJson("/api/orders/{$order->id}/payment", [
                'amount' => 100,
                'method' => 'CASH',
            ])
            ->assertForbidden();
    }

    public function test_a_branch_user_cannot_create_a_branch(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $branchUser = User::factory()->branchUser($branch)->create();

        $this->actingAs($branchUser)
            ->postJson('/api/branches', ['name' => 'Sneaky', 'code' => 'SNK'])
            ->assertForbidden();
    }

    public function test_employees_are_scoped_to_their_own_business(): void
    {
        $abc = Business::factory()->create();
        $xyz = Business::factory()->create();

        $xyzAdmin = User::factory()->admin($xyz)->create();
        $abcEmployee = User::factory()->pickupBoy($abc)->create();

        $this->actingAs($xyzAdmin)
            ->getJson('/api/employees')
            ->assertOk()
            ->assertJsonMissing(['id' => $abcEmployee->id]);
    }

    // -----------------------------------------------------------------
    // KG pricing: section 13
    // -----------------------------------------------------------------

    public function test_kg_price_uses_the_estimate_then_the_measured_weight(): void
    {
        $business = Business::factory()->create([
            'pickup_charge' => 0,
            'delivery_charge' => 0,
        ]);

        $branch = \App\Models\Branch::factory()->for($business)->create();
        $service = Service::factory()->for($business)->perKg()->create(['rate' => 100]);

        $order = app(\App\Services\OrderService::class)->create([
            'branch' => $branch,
            'customer' => null,
            'source' => \App\Enums\OrderSource::ADMIN,
            'lines' => collect([['service_id' => $service->id, 'approx_weight' => 4]]),
            'created_by' => null,
        ]);

        // Quoted on the 4 KG estimate.
        $this->assertSame('400.00', (string) $order->subtotal);
        $this->assertTrue($order->isAwaitingActualWeight());

        $item = $order->items()->firstOrFail();

        app(\App\Services\OrderService::class)->recordActualWeights(
            $order,
            [['item_id' => $item->id, 'actual_weight' => 5.5]]
        );

        $order->refresh();

        // Re-priced on the measured 5.5 KG.
        $this->assertSame('550.00', (string) $order->subtotal);
        $this->assertSame('550.00', (string) $order->total_amount);

        // The estimate is preserved, never overwritten.
        $this->assertSame('4.000', (string) $order->approx_weight);
        $this->assertSame('5.500', (string) $order->actual_weight);
        $this->assertSame('4.000', (string) $item->fresh()->approx_weight);
    }

    public function test_piece_price_is_quantity_times_rate(): void
    {
        $business = Business::factory()->create([
            'pickup_charge' => 0,
            'delivery_charge' => 0,
        ]);

        $branch = \App\Models\Branch::factory()->for($business)->create();
        $service = Service::factory()->for($business)->perPiece()->create(['rate' => 25]);

        $order = app(\App\Services\OrderService::class)->create([
            'branch' => $branch,
            'customer' => null,
            'source' => \App\Enums\OrderSource::WALK_IN,
            'lines' => collect([['service_id' => $service->id, 'quantity' => 8]]),
            'created_by' => null,
        ]);

        $this->assertSame('200.00', (string) $order->subtotal);
        $this->assertFalse($order->isAwaitingActualWeight());
    }

    public function test_rate_is_copied_onto_the_line_so_history_is_stable(): void
    {
        $business = Business::factory()->create(['pickup_charge' => 0, 'delivery_charge' => 0]);
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $service = Service::factory()->for($business)->perPiece()->create(['rate' => 50]);

        $order = app(\App\Services\OrderService::class)->create([
            'branch' => $branch,
            'customer' => null,
            'source' => \App\Enums\OrderSource::ADMIN,
            'lines' => collect([['service_id' => $service->id, 'quantity' => 2]]),
            'created_by' => null,
        ]);

        // Owner changes the price list.
        $service->update(['rate' => 99]);

        $this->assertSame('50.00', (string) $order->items()->firstOrFail()->unit_price);
        $this->assertSame('100.00', (string) $order->fresh()->subtotal);
    }

    public function test_pickup_and_delivery_charges_are_applied(): void
    {
        $business = Business::factory()->create([
            'pickup_charge' => 50,
            'delivery_charge' => 40,
        ]);

        $branch = \App\Models\Branch::factory()->for($business)->create();
        $service = Service::factory()->for($business)->perPiece()->create(['rate' => 10]);

        $order = app(\App\Services\OrderService::class)->create([
            'branch' => $branch,
            'customer' => null,
            'source' => \App\Enums\OrderSource::CUSTOMER,
            'fulfilment' => \App\Enums\FulfilmentType::DELIVERY,
            'lines' => collect([['service_id' => $service->id, 'quantity' => 1]]),
            'created_by' => null,
        ]);

        $this->assertSame('10.00', (string) $order->subtotal);
        $this->assertSame('50.00', (string) $order->pickup_charge);
        $this->assertSame('40.00', (string) $order->delivery_charge);
        $this->assertSame('100.00', (string) $order->total_amount);
    }

    // -----------------------------------------------------------------
    // Order lifecycle: section 14
    // -----------------------------------------------------------------

    public function test_the_full_happy_path_lifecycle_runs_in_order(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create();

        $status = app(\App\Services\OrderStatusService::class);
        $admin = User::factory()->admin($business)->create();

        $path = [
            OrderStatus::PICKUP_ASSIGNED,
            OrderStatus::PICKED_UP,
            OrderStatus::RECEIVED_AT_BRANCH,
            OrderStatus::WEIGHED,
            OrderStatus::PROCESSING,
            OrderStatus::READY,
            OrderStatus::DELIVERY_ASSIGNED,
            OrderStatus::OUT_FOR_DELIVERY,
            OrderStatus::DELIVERED,
            OrderStatus::COMPLETED,
        ];

        foreach ($path as $step) {
            $status->transition($order, $step, $admin);
            $order->refresh();
            $this->assertSame($step, $order->order_status);
        }

        $this->assertTrue($order->isTerminal());
        $this->assertNotNull($order->completed_at);

        // One row per transition, each stamped with the actor who made it.
        $this->assertSame(count($path), $order->statusHistory()->count());
        $this->assertTrue($order->statusHistory()->where('changed_by', $admin->id)->count() === count($path));
    }

    public function test_customer_pickup_is_an_alternative_completion_path(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create();

        $status = app(\App\Services\OrderStatusService::class);
        $admin = User::factory()->admin($business)->create();

        $status->transition($order, OrderStatus::RECEIVED_AT_BRANCH, $admin);
        $status->transition($order, OrderStatus::PROCESSING, $admin);
        $status->transition($order, OrderStatus::READY, $admin);
        $status->transition($order, OrderStatus::CUSTOMER_PICKUP, $admin);
        $status->transition($order, OrderStatus::COMPLETED, $admin);

        $order->refresh();

        $this->assertSame(OrderStatus::COMPLETED, $order->order_status);
    }

    public function test_a_completed_order_cannot_be_reopened(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()
            ->forBusiness($business, $branch)
            ->status(OrderStatus::COMPLETED)
            ->create();

        $this->expectException(\App\Services\OrderTransitionException::class);

        app(\App\Services\OrderStatusService::class)
            ->transition($order, OrderStatus::PROCESSING, User::factory()->admin($business)->create());
    }

    public function test_cancellation_is_recorded_in_the_history(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create();
        $admin = User::factory()->admin($business)->create();

        app(\App\Services\OrderStatusService::class)
            ->cancel($order, $admin, 'Customer changed their mind');

        $order->refresh();

        $this->assertSame(OrderStatus::CANCELLED, $order->order_status);

        $last = $order->statusHistory()->latest('id')->firstOrFail();
        $this->assertSame(OrderStatus::CANCELLED, $last->new_status);
        $this->assertSame('Customer changed their mind', $last->note);
    }

    // -----------------------------------------------------------------
    // Payments: section 18
    // -----------------------------------------------------------------

    public function test_partial_payment_leaves_the_order_partial_then_paid(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create([
            'total_amount' => 500,
        ]);

        $admin = User::factory()->admin($business)->create();
        $payments = app(\App\Services\PaymentService::class);

        $payments->record($order, 200, \App\Enums\PaymentMethod::CASH, $admin);
        $order->refresh();

        $this->assertSame(\App\Enums\PaymentStatus::PARTIAL, $order->payment_status);
        $this->assertSame(300.0, $order->amountDue());

        $payments->record($order->fresh(), 300, \App\Enums\PaymentMethod::CASH, $admin);
        $order->refresh();

        $this->assertSame(\App\Enums\PaymentStatus::PAID, $order->payment_status);
        $this->assertSame(0.0, $order->amountDue());
        $this->assertNotNull($order->paid_at);
    }

    public function test_overpayment_is_refused(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create([
            'total_amount' => 100,
        ]);

        $this->expectException(\App\Services\PaymentException::class);

        app(\App\Services\PaymentService::class)
            ->record($order, 150, \App\Enums\PaymentMethod::CASH, User::factory()->admin($business)->create());
    }

    public function test_upi_payment_requires_a_reference(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create([
            'total_amount' => 100,
        ]);

        $this->expectException(\App\Services\PaymentException::class);

        app(\App\Services\PaymentService::class)
            ->record($order, 100, \App\Enums\PaymentMethod::UPI, User::factory()->admin($business)->create());
    }

    public function test_payment_status_cannot_be_forced_by_the_browser(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create([
            'total_amount' => 300,
        ]);

        $admin = User::factory()->admin($business)->create();

        // The client tries to mark it paid by posting a field.
        $this->actingAs($admin)
            ->postJson("/api/orders/{$order->id}/payment", [
                'amount' => 300,
                'method' => 'CASH',
                'payment_status' => 'PAID',
            ])
            ->assertOk();

        // Which produced a real payment row, not a spoofed flag.
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(\App\Enums\PaymentStatus::PAID, $order->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // Assignment and regions: section 17
    // -----------------------------------------------------------------

    public function test_an_employee_from_another_tenant_cannot_be_assigned(): void
    {
        $abc = Business::factory()->create();
        $xyz = Business::factory()->create();

        $abcBranch = \App\Models\Branch::factory()->for($abc)->create();
        $xyzPickup = User::factory()->pickupBoy($xyz)->create();

        $order = \App\Models\Order::factory()->forBusiness($abc, $abcBranch)->create();

        $this->expectException(\App\Services\AssignmentException::class);

        app(\App\Services\AssignmentService::class)->assignPickup($order, $xyzPickup);
    }

    public function test_a_fully_mapped_employee_cannot_be_assigned_out_of_region(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();

        $kothrud = \App\Models\Region::factory()->for($business)->create(['name' => 'Kothrud']);
        $baner = \App\Models\Region::factory()->for($business)->create(['name' => 'Baner']);

        $pickup = User::factory()->pickupBoy($branch)->create();
        $pickup->regions()->sync([$kothrud->id]);

        $customer = Customer::factory()->forBusiness($business)->create();
        $address = $customer->addresses()->create([
            'address_line' => 'Baner Road, Pune',
            'city' => 'Pune',
        ]);

        $order = \App\Models\Order::factory()
            ->forBusiness($business, $branch)
            ->forCustomer($customer, $branch)
            ->create(['address_id' => $address->id]);

        $assignments = app(\App\Services\AssignmentService::class);

        $this->assertFalse($assignments->servesOrderRegions($pickup, $order));

        // And a pickup in their own region is fine.
        $inRegion = Customer::factory()->forBusiness($business)->create();
        $inRegionAddress = $inRegion->addresses()->create([
            'address_line' => 'Kothrud, Pune',
            'city' => 'Pune',
        ]);

        $order->update(['address_id' => $inRegionAddress->id]);
        $this->assertTrue($assignments->servesOrderRegions($pickup, $order->fresh()));
    }

    public function test_an_inactive_employee_cannot_take_work(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $pickup = User::factory()->pickupBoy($branch)->inactive()->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create();

        $this->expectException(\App\Services\AssignmentException::class);

        app(\App\Services\AssignmentService::class)->assignPickup($order, $pickup);
    }

    // -----------------------------------------------------------------
    // Soft deletion: section 21
    // -----------------------------------------------------------------

    public function test_deactivating_a_customer_keeps_their_historic_orders(): void
    {
        $business = Business::factory()->create();
        $customer = Customer::factory()->forBusiness($business)->create();
        $order = \App\Models\Order::factory()->forCustomer($customer)->create();

        $customer->forceFill(['status' => 'INACTIVE'])->save();

        $this->assertSame(1, $order->fresh()->customer->count());
        $this->assertSame($order->id, $customer->orders()->firstOrFail()->id);
    }

    public function test_deactivating_an_employee_keeps_their_assignment_history(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $pickup = User::factory()->pickupBoy($branch)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create();

        app(\App\Services\AssignmentService::class)->assignPickup($order, $pickup);

        $pickup->forceFill(['status' => 'INACTIVE'])->save();

        $this->assertSame(1, $order->fresh()->pickupAssignments()->count());
        $this->assertTrue($pickup->pickupAssignments()->exists());
    }

    // -----------------------------------------------------------------
    // Audit trail: section 21
    // -----------------------------------------------------------------

    public function test_sensitive_actions_are_audit_logged(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $order = \App\Models\Order::factory()->forBusiness($business, $branch)->create([
            'total_amount' => 100,
        ]);

        $admin = User::factory()->admin($business)->create();

        $this->actingAs($admin);

        app(\App\Services\PaymentService::class)
            ->record($order, 100, \App\Enums\PaymentMethod::CASH, $admin);

        $this->assertTrue(\App\Models\AuditLog::where('action', 'created')->exists());
    }

    // -----------------------------------------------------------------
    // Order creation via the API
    // -----------------------------------------------------------------

    public function test_a_branch_user_cannot_create_an_order_for_another_branch(): void
    {
        $business = Business::factory()->create();
        $branchA = \App\Models\Branch::factory()->for($business)->create();
        $branchB = \App\Models\Branch::factory()->for($business)->create();
        $service = Service::factory()->for($business)->perPiece()->create();

        $branchUser = User::factory()->branchUser($branchA)->create();

        $this->actingAs($branchUser)
            ->postJson('/api/orders', [
                'branch_id' => $branchB->id,
                'lines' => [['service_id' => $service->id, 'quantity' => 1]],
            ])
            // Forbidden, not a validation error: the branch exists, this
            // user simply may not put orders into it.
            ->assertForbidden();

        $this->assertSame(0, \App\Models\Order::count());
    }

    public function test_order_number_is_unique_within_the_tenant(): void
    {
        $abc = Business::factory()->create(['business_name' => 'ABC Laundry']);
        $xyz = Business::factory()->create(['business_name' => 'ABC Laundry']);

        $abcBranch = \App\Models\Branch::factory()->for($abc)->create();
        $xyzBranch = \App\Models\Branch::factory()->for($xyz)->create();

        $abcService = Service::factory()->for($abc)->perPiece()->create();
        $xyzService = Service::factory()->for($xyz)->perPiece()->create();

        $orders = app(\App\Services\OrderService::class);

        $first = $orders->create([
            'branch' => $abcBranch,
            'customer' => null,
            'source' => \App\Enums\OrderSource::ADMIN,
            'lines' => collect([['service_id' => $abcService->id, 'quantity' => 1]]),
            'created_by' => null,
        ]);

        $second = $orders->create([
            'branch' => $xyzBranch,
            'customer' => null,
            'source' => \App\Enums\OrderSource::ADMIN,
            'lines' => collect([['service_id' => $xyzService->id, 'quantity' => 1]]),
            'created_by' => null,
        ]);

        // Same prefix, but unique per business.
        $this->assertSame('ABC-000001', $first->order_number);
        $this->assertSame('ABC-000001', $second->order_number);
    }

    public function test_walk_in_and_online_orders_use_the_same_table_and_lifecycle(): void
    {
        $business = Business::factory()->create();
        $branch = \App\Models\Branch::factory()->for($business)->create();
        $service = Service::factory()->for($business)->perPiece()->create();

        $orders = app(\App\Services\OrderService::class);

        $online = $orders->create([
            'branch' => $branch,
            'customer' => null,
            'source' => \App\Enums\OrderSource::CUSTOMER,
            'lines' => collect([['service_id' => $service->id, 'quantity' => 1]]),
            'created_by' => null,
        ]);

        $walkIn = $orders->create([
            'branch' => $branch,
            'customer' => null,
            'source' => \App\Enums\OrderSource::WALK_IN,
            'lines' => collect([['service_id' => $service->id, 'quantity' => 1]]),
            'created_by' => null,
        ]);

        $this->assertSame('orders', $online->getTable());
        $this->assertSame('orders', $walkIn->getTable());
        $this->assertNotSame($online->order_status, null);
        $this->assertSame(
            $online->order_status,
            $walkIn->order_status
        );
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    public function test_a_guest_gets_a_401_from_the_api_not_a_redirect(): void
    {
        // An API caller needs a status code it can act on, not an HTML
        // login page it would try to parse as JSON.
        $this->getJson('/api/orders')->assertUnauthorized();
    }

    public function test_a_guest_browsing_the_ui_is_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_the_textbee_driver_refuses_to_run_without_an_api_key(): void
    {
        config(['sms.textbee.api_key' => null]);

        $driver = new TextBeeDriver(
            apiKey: null,
            baseUrl: 'https://api.textbee.dev/api/v1/gateway/send-sms',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TEXTBEE_API_KEY/');

        $driver->send(['+919876543210'], 'test');
    }

    public function test_pricing_type_labels_are_distinct(): void
    {
        $this->assertSame('Per KG', PricingType::KG->label());
        $this->assertSame('Per Piece', PricingType::PIECE->label());
    }

    /**
     * Swap the SMS transport for one that records instead of sending.
     */
    private function fakeSms(): void
    {
        $this->app->instance(SmsDriver::class, new class implements SmsDriver
        {
            public function send(array $recipients, string $message): SmsResult
            {
                return SmsResult::accepted('fake');
            }
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Real hashing, real rate limiter, but no outbound SMS.
        RateLimiter::clear('otp-send-mobile:+919876543214');
        RateLimiter::clear('otp-send-ip:127.0.0.1');
    }
}
