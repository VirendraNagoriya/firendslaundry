<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\IdentityException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\IdentityService;
use App\Support\Identity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password sign-in and self-registration.
 *
 * Google sign-in lives in GoogleAuthController. Both end in the same place:
 * an Identity, which IdentityService turns into the correct profile.
 *
 * A note on email verification. Self-registration marks the address
 * *unverified*, because this codebase has not proved the person controls
 * it. Google sign-in, by contrast, is trusted: Google verified it.
 *
 * The consequence is deliberate and worth stating plainly: there is no
 * "forgot password" flow. Resetting by email would hand an account to
 * whoever can read that inbox, and until ownership of the address is
 * provable, that is a way to take over a staff account. The moment email
 * verification is wired up, reset can be added safely.
 */
class AuthController extends Controller
{
    public function __construct(private readonly IdentityService $identity)
    {
    }

    public function showLogin(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'googleEnabled' => $this->googleAvailable(),
            'passwordEnabled' => (bool) config('signin.password.enabled'),
            'registrationOpen' => (bool) config('signin.registration.open'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $this->ensurePasswordSignInIsEnabled();

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($credentials['email']));

        // Throttled on the email as well as the IP, so a single account
        // cannot be ground down from one host, and many accounts cannot be
        // ground down from many hosts.
        $throttleKey = 'login:'.hash('sha256', $email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('signin.throttle.login_attempts'))) {
            throw ValidationException::withMessages([
                'email' => [
                    'Too many sign-in attempts. Try again in '
                    .ceil(RateLimiter::availableIn($throttleKey) / 60).' minute(s).',
                ],
            ]);
        }

        $user = User::query()->withoutGlobalScopes()->where('email', $email)->first();

        // Compared only when there is a password to compare against. A
        // Google-only account has none, and passing null into Hash::check()
        // is a TypeError, which would turn a refused sign-in into a 500.
        $passwordMatches = $user !== null
            && $user->usesPassword()
            && Hash::check($credentials['password'], $user->password);

        if (! $passwordMatches) {
            RateLimiter::hit(
                $throttleKey,
                (int) config('signin.throttle.login_decay_minutes') * 60,
            );

            // One message for "no such account" and "wrong password" alike,
            // so this form cannot be used to discover which addresses exist.
            throw ValidationException::withMessages([
                'email' => [IdentityException::invalidCredentials()->getMessage()],
            ]);
        }

        RateLimiter::clear($throttleKey);

        // The password was correct, but the account may have been switched
        // off since. login() refuses that; it is a refusal, not a crash.
        try {
            $this->identity->login($user);
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([
                'email' => [$e->getMessage()],
            ]);
        }

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Welcome back, '.$user->name.'.');
    }

    public function showRegister(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        abort_unless((bool) config('signin.registration.open'), 403);

        return view('auth.register', [
            'googleEnabled' => $this->googleAvailable(),
            'minLength' => (int) config('signin.password.min_length'),
        ]);
    }

    /**
     * Create a first-party account.
     *
     * This can only ever produce a customer of the laundry whose short code
     * was typed. The role is set in IdentityService and cannot be influenced
     * by anything in the form.
     */
    public function register(Request $request): RedirectResponse
    {
        abort_unless((bool) config('signin.registration.open'), 403);

        $this->ensurePasswordSignInIsEnabled();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [
                'required',
                'string',
                'min:'.config('signin.password.min_length'),
                'max:'.config('signin.password.max_length'),
                'confirmed',
            ],
            'mobile' => ['required', 'string', 'max:25'],
            'business_code' => ['required', 'string', 'max:40'],
        ]);

        try {
            $user = DB::transaction(function () use ($validated) {
                $email = Str::lower(trim($validated['email']));

                // Checked before anything is written. `resolveUser` will
                // happily return an existing account that matches this
                // address, and setting a password on it would hand that
                // account to whoever typed the address.
                $this->identity->assertEmailAvailable($email);

                $identity = Identity::fromPassword(
                    email: $email,
                    name: $validated['name'],
                    mobile: $validated['mobile'],
                    // Not proved: nobody has shown they can read this inbox.
                    emailVerified: false,
                );

                $user = $this->identity->resolveUser(
                    $identity,
                    businessCode: $validated['business_code'],
                    mobile: $validated['mobile'],
                    name: $validated['name'],
                );

                // resolveUser may have adopted a staff record an Admin
                // created. That row keeps its role, and must not quietly
                // acquire a password it never had.
                if (! $user->usesPassword()) {
                    $user->forceFill(['password' => $validated['password']])->save();
                }

                return $user;
            });
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([
                $this->fieldFor($e->reason) => [$e->getMessage()],
            ]);
        }

        // A brand new account is created ACTIVE, so this only trips if the
        // record adopted above had been switched off by an Admin.
        try {
            $this->identity->login($user);
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([
                'mobile' => [$e->getMessage()],
            ]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Your account is ready, '.$user->name.'.');
    }

    public function logout(): RedirectResponse
    {
        $this->identity->logout();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    private function googleAvailable(): bool
    {
        return (bool) config('signin.google.enabled')
            && filled(config('signin.google.client_id'))
            && filled(config('signin.google.client_secret'));
    }

    private function ensurePasswordSignInIsEnabled(): void
    {
        abort_unless((bool) config('signin.password.enabled'), 404);
    }

    /**
     * Put the message next to the field it is about, rather than blaming the
     * laundry code for an email problem.
     */
    private function fieldFor(string $reason): string
    {
        return match ($reason) {
            'email_taken' => 'email',
            'mobile_required', 'name_required' => 'mobile',
            default => 'business_code',
        };
    }
}
