<?php

use App\Http\Controllers\Web\AuthWebController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\OrderWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The browser UI. Sign-in is handled by Supabase Auth: the person proves
| control of a phone number, and this app maps that Supabase account onto
| a row in `users`, which is where the role, business and branch live.
|
| `login` and `login.verify` are named because the framework redirects
| unauthenticated visitors here. An API caller that expects JSON gets a
| 401 instead of a redirect (see bootstrap/app.php).
|
*/

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthWebController::class, 'showLogin'])->name('login');
    Route::post('/login/send-code', [AuthWebController::class, 'sendCode'])->name('login.send');
    Route::get('/login/verify', [AuthWebController::class, 'showVerify'])->name('login.verify');
    Route::post('/login/verify', [AuthWebController::class, 'verifyCode'])->name('login.confirm');
});

Route::post('/logout', [AuthWebController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/orders', [OrderWebController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderWebController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/status', [OrderWebController::class, 'updateStatus'])->name('orders.status');
    Route::post('/orders/{order}/cancel', [OrderWebController::class, 'cancel'])->name('orders.cancel');
});
