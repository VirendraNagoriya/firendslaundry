<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\IdentityException;
use App\Http\Controllers\Controller;
use App\Services\Auth\IdentityService;
use App\Support\Identity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Sign in with Google.
 *
 * Deliberately *not* stateless. Socialite's `stateless()` mode drops the
 * `state` round trip, which is the only thing proving that the person who
 * started the sign-in is the person who finished it. Without it, an
 * attacker can begin a Google flow with their own account and walk a victim
 * silently into it — login CSRF, ending with the victim's clicks recorded
 * against the attacker's account.
 *
 * No token ever appears in a URL and none is handed to JavaScript. The
 * browser gets a normal session cookie and the page is re-rendered.
 */
class GoogleAuthController extends Controller
{
    /**
     * Where the identity waits between Google's callback and the person
     * naming their laundry. Held in the session, so it cannot be supplied
     * or altered by the next request.
     */
    private const PENDING = 'google.pending_identity';

    public function __construct(private readonly IdentityService $identity)
    {
    }

    /**
     * Send the person to Google.
     */
    public function redirect(): RedirectResponse
    {
        abort_unless($this->googleAvailable(), 404);

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    /**
     * Google sends the person back here.
     */
    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->googleAvailable(), 404);

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            // Consent refused, the code replayed, the token exchange failed.
            // None of that is the person's problem to debug.
            return $this->fail(IdentityException::providerFailed());
        }

        $identity = $this->identityFromGoogle($googleUser);

        if ($identity === null) {
            return $this->fail(IdentityException::providerFailed());
        }

        try {
            $user = $this->identity->resolveUser($identity);
        } catch (IdentityException $e) {
            // A Google account we have never seen still has to be attached to
            // a laundry, so ask which one. Anything else is a real refusal and
            // is reported as such.
            if ($e->reason === 'business_required') {
                $request->session()->put(self::PENDING, $this->pendingFrom($identity));

                return redirect()->route('login.google.setup');
            }

            return $this->fail($e);
        }

        $request->session()->forget(self::PENDING);

        if ($refusal = $this->signIn($user)) {
            return $this->fail($refusal);
        }

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Welcome, '.$user->name.'.');
    }

    /**
     * Name the laundry, for a first-time Google sign-in.
     */
    public function showSetup(Request $request): \Illuminate\View\View|RedirectResponse
    {
        abort_unless($this->googleAvailable(), 404);

        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        if ($request->session()->get(self::PENDING) === null) {
            return redirect()->route('login');
        }

        return view('auth.google-setup', [
            'googleName' => (string) $request->session()->get(self::PENDING.'.name'),
            'googleEmail' => (string) $request->session()->get(self::PENDING.'.email'),
        ]);
    }

    public function setup(Request $request): RedirectResponse
    {
        abort_unless($this->googleAvailable(), 404);

        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || ! filled($pending['provider_id'] ?? null)) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'business_code' => ['required', 'string', 'max:40'],
            'mobile' => ['required', 'string', 'max:25'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $identity = Identity::fromGoogle(
            sub: (string) $pending['provider_id'],
            email: $pending['email'] ?? null,
            emailVerified: (bool) ($pending['email_verified'] ?? false),
            name: $pending['name'] ?? null,
            avatar: $pending['avatar'] ?? null,
        );

        try {
            $user = $this->identity->resolveUser(
                $identity,
                businessCode: $validated['business_code'],
                mobile: $validated['mobile'],
                name: $validated['name'] ?? $pending['name'] ?? null,
            );
        } catch (IdentityException $e) {
            return back()
                ->withInput($request->only('business_code', 'mobile', 'name'))
                ->withErrors(['business_code' => $e->getMessage()]);
        }

        $request->session()->forget(self::PENDING);

        if ($refusal = $this->signIn($user)) {
            return back()
                ->withInput($request->only('business_code', 'mobile', 'name'))
                ->withErrors(['mobile' => $refusal->getMessage()]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Welcome, '.$user->name.'.');
    }

    /**
     * Abandon the half-finished sign-in and start over.
     *
     * The pending identity has to be dropped, otherwise a later visit to
     * this page would silently resume the abandoned attempt.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDING);

        return redirect()->route('login');
    }

    /**
     * Sign a Google person in, or explain why not.
     *
     * The account may have been deactivated since it was set up, and an
     * Admin's decision to switch somebody off should read as a refusal
     * rather than surface as a server error.
     */
    private function signIn($user): ?IdentityException
    {
        try {
            $this->identity->login($user);
        } catch (IdentityException $e) {
            return $e;
        }

        return null;
    }

    /**
     * Read Google's claims into our own identity type.
     *
     * `sub` is the account's permanent identifier. The email is kept but is
     * never used on its own to find an account, because Google's email
     * claim is only as trustworthy as the `email_verified` flag beside it.
     */
    private function identityFromGoogle($googleUser): ?Identity
    {
        $sub = $googleUser->getId();

        if (! is_string($sub) || $sub === '') {
            return null;
        }

        $claims = (array) ($googleUser->user ?? []);

        return Identity::fromGoogle(
            sub: $sub,
            email: $googleUser->getEmail(),
            emailVerified: (bool) ($claims['email_verified'] ?? false),
            name: $googleUser->getName(),
            avatar: $googleUser->getAvatar(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingFrom(Identity $identity): array
    {
        return [
            'provider_id' => $identity->providerId,
            'email' => $identity->email,
            'email_verified' => $identity->emailVerified,
            'name' => $identity->name,
            'avatar' => $identity->avatar,
        ];
    }

    private function fail(IdentityException $e): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['email' => $e->getMessage()]);
    }

    private function googleAvailable(): bool
    {
        return (bool) config('signin.google.enabled')
            && filled(config('signin.google.client_id'))
            && filled(config('signin.google.client_secret'));
    }
}
