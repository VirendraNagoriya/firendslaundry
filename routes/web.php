<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\GoogleAuthController;
use App\Http\Controllers\Web\OrderWebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The browser UI. Sign-in is handled by this app: either a Google account,
| or an email and password checked here. Both produce the same thing — a
| session cookie — and neither puts a token in a URL or in JavaScript.
|
| Whichever way somebody arrives, the role, business and branch come from a
| `users` row that already existed. Nothing at the login screen can grant a
| role.
|
| `login` is named because the framework redirects unauthenticated visitors
| here. An API caller that expects JSON gets a 401 instead of a redirect
| (see bootstrap/app.php).
|
*/

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');

    // First-party password accounts.
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');

    // Google. `state` is generated and checked automatically, which is why
    // these are ordinary stateful routes rather than a stateless token
    // exchange. The callback must be public and must not be session-guarded:
    // it *is* the moment the session is created.
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('login.google');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('login.google.callback');

    // Google has said who this is, but not which laundry. Only reachable
    // when the callback left an identity in the session.
    Route::get('/auth/google/setup', [GoogleAuthController::class, 'showSetup'])->name('login.google.setup');
    Route::post('/auth/google/setup', [GoogleAuthController::class, 'setup'])->name('login.google.setup.store');

    // Abandon the attempt and clear the pending identity.
    Route::get('/auth/google/cancel', [GoogleAuthController::class, 'cancel'])->name('login.google.cancel');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/orders', [OrderWebController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderWebController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/status', [OrderWebController::class, 'updateStatus'])->name('orders.status');
    Route::post('/orders/{order}/cancel', [OrderWebController::class, 'cancel'])->name('orders.cancel');
});
