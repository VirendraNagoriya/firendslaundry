<?php

use App\Enums\Role;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| The spec's REST surface (section 20). These routes carry the "web"
| middleware group on purpose: the frontend is server-rendered Blade, so
| the same-origin session cookie authenticates the calls and CSRF
| protection stays switched on. The TextBee API key is never reachable
| from here.
|
| There is deliberately no /api/auth group. Sign-in is a browser concern —
| Google OAuth and password sign-in both live in routes/web.php and finish
| with a session cookie. Issuing a token to a script would mean shipping
| bearer auth, which this app does not do.
|
*/

Route::middleware(['web'])->group(function () {

    // ---- Everything below requires a signed-in session ----------------
    Route::middleware('auth')->group(function () {
        Route::get('me', MeController::class);

        // Orders
        Route::get('orders', [OrderController::class, 'index']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::post('orders/{order}/assign-pickup', [OrderController::class, 'assignPickup']);
        Route::post('orders/{order}/assign-delivery', [OrderController::class, 'assignDelivery']);
        Route::post('orders/{order}/weight', [OrderController::class, 'recordWeight']);
        Route::post('orders/{order}/payment', [OrderController::class, 'recordPayment']);

        // Branches (Admin/Owner, and Super Admin acting on a client)
        Route::get('branches', [BranchController::class, 'index'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);
        Route::post('branches', [BranchController::class, 'store'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);
        Route::patch('branches/{branch}', [BranchController::class, 'update'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);

        // Employees
        Route::get('employees', [EmployeeController::class, 'index'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);
        Route::post('employees', [EmployeeController::class, 'store'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);
        Route::patch('employees/{user}/status', [EmployeeController::class, 'updateStatus'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);

        // Regions
        Route::get('regions', [RegionController::class, 'index']);
        Route::post('regions', [RegionController::class, 'store'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);
        Route::patch('regions/{region}', [RegionController::class, 'update'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);

        // Service catalogue and pricing
        Route::get('services', [ServiceController::class, 'index']);
        Route::post('services', [ServiceController::class, 'store'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);
        Route::patch('services/{service}', [ServiceController::class, 'update'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);

        // Customers
        Route::get('customers', [CustomerController::class, 'index'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value.','.Role::BRANCH->value);
        Route::post('customers', [CustomerController::class, 'store'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value.','.Role::BRANCH->value);
        Route::patch('customers/{customer}/status', [CustomerController::class, 'updateStatus'])
            ->middleware('role:'.Role::SUPER_ADMIN->value.','.Role::ADMIN->value);
    });

});
