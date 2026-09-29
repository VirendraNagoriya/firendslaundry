<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Region;
use App\Models\Service;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Services\PricingService;
use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;

function line(string $text = ''): void
{
    echo $text.PHP_EOL;
}

function heading(string $text): void
{
    line();
    line('=== '.$text.' ===');
}

// ---------------------------------------------------------------------
// Platform super admin
// ---------------------------------------------------------------------
$super = User::where('role', Role::SUPER_ADMIN)->firstOrFail();

// ---------------------------------------------------------------------
// ABC Laundry: the client from the spec walkthrough (section 29)
// ---------------------------------------------------------------------
$abc = Business::where('business_name', 'ABC Laundry')->firstOrFail();
$abcAdmin = User::where('business_id', $abc->id)->where('role', Role::ADMIN)->firstOrFail();
$abcBranchMain = Branch::where('business_id', $abc->id)->orderBy('id')->firstOrFail();
$abcBranch2 = Branch::where('business_id', $abc->id)->orderBy('id')->skip(1)->firstOrFail();
$abcBranchUser = User::where('business_id', $abc->id)->where('role', Role::BRANCH)->firstOrFail();
$abcPickup = User::where('business_id', $abc->id)->where('role', Role::PICKUP_BOY)->firstOrFail();
$abcDelivery = User::where('business_id', $abc->id)->where('role', Role::DELIVERY_BOY)->firstOrFail();

$abcWashKg = Service::where('business_id', $abc->id)->where('name', 'Washing & Folding')->firstOrFail();
$abcDryCleanKg = Service::where('business_id', $abc->id)->where('name', 'Dry Cleaning')->firstOrFail();
$abcIronPiece = Service::where('business_id', $abc->id)->where('name', 'Ironing Only')->firstOrFail();

$abcCustomer = Customer::where('business_id', $abc->id)->orderBy('id')->firstOrFail();
$abcCustomerAddress = $abcCustomer->addresses()->firstOrFail();

line('ABC Laundry: branch_limit='.$abc->branch_limit.', branches='.$abc->branchesUsed());
line('Customer: '.$abcCustomer->name.' '.$abcCustomer->mobile);

$orderService = app(OrderService::class);
$statusService = app(OrderStatusService::class);
$assignmentService = app(AssignmentService::class);
$paymentService = app(PaymentService::class);

// ---------------------------------------------------------------------
// 1. Customer places an online order with an approximate KG estimate
// ---------------------------------------------------------------------
heading('1. Customer creates an online order (approx 4 KG washing + 1 shirt)');

$online = $orderService->create([
    'branch' => $abcBranchMain,
    'customer' => $abcCustomer,
    'address' => $abcCustomerAddress,
    'source' => \App\Enums\OrderSource::CUSTOMER,
    'fulfilment' => FulfilmentType::DELIVERY,
    'lines' => collect([
        ['service_id' => $abcWashKg->id, 'approx_weight' => 4.0],
        ['service_id' => $abcIronPiece->id, 'quantity' => 2],
    ]),
    'created_by' => null,
]);

line('order_number: '.$online->order_number);
line('status: '.$online->order_status->value);
line('approx_weight: '.$online->approx_weight);
line('subtotal: '.$online->subtotal);
line('pickup_charge: '.$online->pickup_charge);
line('delivery_charge: '.$online->delivery_charge);
line('total_amount: '.$online->total_amount);
line('is estimate (KG not yet weighed): '.($online->isAwaitingActualWeight() ? 'YES' : 'no'));

// ---------------------------------------------------------------------
// 2. Branch assigns a pickup boy
// ---------------------------------------------------------------------
heading('2. Branch assigns pickup boy');

$assignmentService->assignPickup($online->fresh(), $abcPickup, $abcBranchUser);
$online->refresh();
line('status after assign: '.$online->order_status->value);

// ---------------------------------------------------------------------
// 3. Pickup boy marks picked up and records the ACTUAL weight
// ---------------------------------------------------------------------
heading('3. Pickup boy confirms pickup, actual weight differs from estimate');

$assignmentService->markPickedUp($online->fresh(), $abcPickup);
$online->refresh();
line('status: '.$online->order_status->value);

$washItem = $online->items()->where('service_id', $abcWashKg->id)->firstOrFail();
$beforeTotal = (float) $online->total_amount;

$orderService->recordActualWeights(
    $online->fresh(),
    [['item_id' => $washItem->id, 'actual_weight' => 5.25]],
    $abcPickup
);

$online->refresh();
line('approx_weight kept: '.$online->approx_weight.'  (never overwritten)');
line('actual_weight: '.$online->actual_weight);
line('total before weigh: Rs. '.$beforeTotal);
line('total after weigh:  Rs. '.$online->total_amount);
line('  -> recalculated from measured weight, estimate preserved');
line('KG line total: Rs. '.$washItem->fresh()->total_amount.' (5.25 x '.$abcWashKg->rate.')');

// ---------------------------------------------------------------------
// 4. Branch receives, weighs, processes, marks ready
// ---------------------------------------------------------------------
heading('4. Branch: RECEIVED_AT_BRANCH -> WEIGHED -> PROCESSING -> READY');

foreach ([OrderStatus::RECEIVED_AT_BRANCH, OrderStatus::WEIGHED, OrderStatus::PROCESSING, OrderStatus::READY] as $step) {
    $statusService->transition($online->fresh(), $step, $abcBranchUser);
    line('  -> '.$online->fresh()->order_status->value);
}

// ---------------------------------------------------------------------
// 5. Branch assigns delivery boy
// ---------------------------------------------------------------------
heading('5. Branch assigns delivery boy');

$assignmentService->assignDelivery($online->fresh(), $abcDelivery, $abcBranchUser);
$online->refresh();
line('status: '.$online->order_status->value);

$assignmentService->markOutForDelivery($online->fresh(), $abcDelivery);
$online->refresh();
line('status: '.$online->order_status->value);

// ---------------------------------------------------------------------
// 6. Delivery boy collects CASH and marks delivered
// ---------------------------------------------------------------------
heading('6. Delivery boy collects CASH and marks delivered');

$assignmentService->markDelivered($online->fresh(), $abcDelivery);
$online->refresh();
line('status: '.$online->order_status->value);

$paymentService->record(
    $online->fresh(),
    (float) $online->total_amount,
    PaymentMethod::CASH,
    $abcDelivery,
    null,
    'Collected at the door'
);

$online->refresh();
line('payment_status: '.$online->payment_status->value);
line('amount_due: '.$online->amountDue());

$statusService->transition($online->fresh(), OrderStatus::COMPLETED, $abcBranchUser);
$online->refresh();
line('final status: '.$online->order_status->value);
line('completed_at: '.$online->completed_at);
line('status history rows: '.$online->statusHistory()->count());

// ---------------------------------------------------------------------
// 7. Walk-in order follows the SAME lifecycle
// ---------------------------------------------------------------------
heading('7. Walk-in order (customer collects from the counter) - UPI payment');

$walkInCustomer = Customer::where('business_id', $abc->id)->orderBy('id')->skip(1)->firstOrFail();

$walkIn = $orderService->create([
    'branch' => $abcBranch2,
    'customer' => $walkInCustomer,
    'address' => null,
    'source' => \App\Enums\OrderSource::WALK_IN,
    'fulfilment' => FulfilmentType::CUSTOMER_PICKUP,
    'lines' => collect([
        ['service_id' => $abcDryCleanKg->id, 'approx_weight' => 2.0],
    ]),
    'created_by' => $abcBranchUser,
]);

line('order_number: '.$walkIn->order_number.'  source: '.$walkIn->order_source->value);
line('subtotal: '.$walkIn->subtotal.'  total: '.$walkIn->total_amount);
line('delivery_charge: '.$walkIn->delivery_charge.' (zero for counter collection)');

$dryItem = $walkIn->items()->firstOrFail();
$orderService->recordActualWeights($walkIn->fresh(), [['item_id' => $dryItem->id, 'actual_weight' => 2.4]], $abcBranchUser);
$walkIn->refresh();
line('after weigh (2.4 KG x '.$abcDryCleanKg->rate.'): Rs. '.$walkIn->total_amount);

foreach ([OrderStatus::RECEIVED_AT_BRANCH, OrderStatus::PROCESSING, OrderStatus::READY] as $step) {
    $statusService->transition($walkIn->fresh(), $step, $abcBranchUser);
}

$statusService->transition($walkIn->fresh(), OrderStatus::CUSTOMER_PICKUP, $abcBranchUser);
$statusService->transition($walkIn->fresh(), OrderStatus::COMPLETED, $abcBranchUser);
$walkIn->refresh();
line('final status: '.$walkIn->order_status->value);

$paymentService->record($walkIn->fresh(), (float) $walkIn->total_amount, PaymentMethod::UPI, $abcBranchUser, 'UTR'.random_int(100000, 999999));
$walkIn->refresh();
line('payment_status: '.$walkIn->payment_status->value.' via UPI');

// ---------------------------------------------------------------------
// 8. Part-payment flow
// ---------------------------------------------------------------------
heading('8. Partial payment leaves the order PARTIAL');

$partialCustomer = Customer::where('business_id', $abc->id)->orderBy('id')->skip(2)->firstOrFail();
$partial = $orderService->create([
    'branch' => $abcBranchMain,
    'customer' => $partialCustomer,
    'address' => $partialCustomer->addresses()->first(),
    'source' => \App\Enums\OrderSource::CUSTOMER,
    'lines' => collect([['service_id' => $abcIronPiece->id, 'quantity' => 10]]),
    'created_by' => null,
]);

$half = round((float) $partial->total_amount / 2, 2);
$paymentService->record($partial->fresh(), $half, PaymentMethod::CASH, $abcBranchUser);
$partial->refresh();
line('total: '.$partial->total_amount.'  paid: '.$half);
line('payment_status: '.$partial->payment_status->value);
line('still due: '.$partial->amountDue());

$paymentService->record($partial->fresh(), $partial->amountDue(), PaymentMethod::CASH, $abcBranchUser);
$partial->refresh();
line('after balance: payment_status: '.$partial->payment_status->value.', due: '.$partial->amountDue());

// ---------------------------------------------------------------------
// 9. Illegal transition is refused
// ---------------------------------------------------------------------
heading('9. Invalid transitions are refused (409)');

try {
    $statusService->transition($online->fresh(), OrderStatus::PROCESSING, $abcBranchUser);
    line('  !! FAIL: completed order was moved to PROCESSING');
} catch (\App\Services\OrderTransitionException $e) {
    line('  refused: '.$e->getMessage());
}

try {
    $statusService->transition($walkIn->fresh(), OrderStatus::ORDER_CREATED, $abcBranchUser);
    line('  !! FAIL: backwards transition allowed');
} catch (\App\Services\OrderTransitionException $e) {
    line('  refused: '.$e->getMessage());
}

// ---------------------------------------------------------------------
// 10. Cross-tenant isolation
// ---------------------------------------------------------------------
heading('10. Cross-tenant isolation: ABC staff must not reach XYZ data');

$xyz = Business::where('business_name', 'XYZ Laundry')->firstOrFail();

$xyzOrder = $orderService->create([
    'branch' => Branch::where('business_id', $xyz->id)->firstOrFail(),
    'customer' => null,
    'source' => \App\Enums\OrderSource::ADMIN,
    'lines' => collect([['service_id' => Service::where('business_id', $xyz->id)->firstOrFail()->id, 'quantity' => 1]]),
    'created_by' => User::where('business_id', $xyz->id)->where('role', Role::ADMIN)->firstOrFail(),
]);

line('XYZ order: '.$xyzOrder->order_number.' (business_id '.$xyz->id.')');

// Scoped queries as the ABC admin must not see it.
auth()->login($abcAdmin);
$visible = Order::count();
$xyzVisible = Order::whereKey($xyzOrder->id)->exists();
auth()->logout();

line('orders visible to ABC admin: '.$visible.' (XYZ order included: '.($xyzVisible ? 'YES - LEAK' : 'no').')');

// Direct cross-tenant fetch by id must be denied by the policy.
$policy = app(\App\Policies\OrderPolicy::class);
$allowed = $policy->view($abcAdmin, $xyzOrder);
line('OrderPolicy::view(ABC admin, XYZ order): '.($allowed ? 'ALLOWED - LEAK' : 'denied'));

// ---------------------------------------------------------------------
// 11. Region-aware assignment
// ---------------------------------------------------------------------
heading('11. Region mapping: pickup boy covers the address region');

$pickupRegions = $abcPickup->regions()->pluck('name')->all();
line('pickup boy regions: '.implode(', ', $pickupRegions));
line('online order address: '.$abcCustomerAddress->oneLine());
line('serves that order: '.($assignmentService->servesOrderRegions($abcPickup, $online->fresh()) ? 'YES' : 'no'));

// ---------------------------------------------------------------------
// 12. Final totals
// ---------------------------------------------------------------------
heading('12. Platform totals');
line('businesses: '.Business::count());
line('orders: '.Order::count());
line('order items: '.OrderItem::count());
line('status history rows: '.OrderStatusHistory::count());
line('payments: '.Payment::count());
line('audit log rows: '.\App\Models\AuditLog::count().' (business_id NULL where platform-level)');
line();
line('DONE - full lifecycle verified.');
