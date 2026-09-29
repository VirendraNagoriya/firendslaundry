<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\SupabaseAuthException;
use App\Http\Controllers\Controller;
use App\Services\Auth\SupabaseAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Browser sign-in.
 *
 * Two steps, both handled by Supabase Auth: ask for a code, then verify it.
 * This controller only decides which page to show and which laundry the
 * person is signing in to.
 */
class AuthWebController extends Controller
{
    public function __construct(private readonly SupabaseAuthService $auth)
    {
    }

    public function showLogin(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'configured' => $this->auth->isConfigured(),
            'identifierLabel' => config('supabase.identifier') === 'email' ? 'Email address' : 'Mobile number',
            'identifierType' => config('supabase.identifier') === 'email' ? 'email' : 'tel',
            'identifierMode' => config('supabase.identifier') === 'email' ? 'email' : 'tel',
        ]);
    }

    /**
     * Step one: ask Supabase to text or email a one-time code.
     */
    public function sendCode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:120'],
        ]);

        try {
            $this->auth->sendLoginCode($validated['identifier']);
        } catch (SupabaseAuthException $e) {
            return back()->withInput($request->only('identifier'))->withErrors([
                'identifier' => $e->getMessage(),
            ]);
        }

        // The identifier is put back in the session so the code screen does
        // not need it in the form, and so it cannot be edited between the
        // two steps to verify against a different account.
        $request->session()->put('login.identifier', $validated['identifier']);
        $request->session()->put('login.business_code', $request->string('business_code')->trim()->value());
        $request->session()->put('login.name', $request->string('name')->trim()->value());

        return redirect()->route('login.verify');
    }

    /**
     * Step two: swap the code for a session.
     */
    public function showVerify(Request $request): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        if ($request->session()->get('login.identifier') === null) {
            return redirect()->route('login');
        }

        return view('auth.verify', [
            'identifier' => (string) $request->session()->get('login.identifier'),
            'businessCode' => (string) $request->session()->get('login.business_code', ''),
            'name' => (string) $request->session()->get('login.name', ''),
            'expiryMinutes' => (int) config('supabase.otp_expiry_minutes'),
        ]);
    }

    /**
     * Step two: swap the code for a session.
     */
    public function verifyCode(Request $request): RedirectResponse
    {
        $identifier = (string) $request->session()->get('login.identifier', '');

        if ($identifier === '') {
            return redirect()->route('login')->withErrors([
                'identifier' => 'Request a new code to sign in.',
            ]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:12'],
            'business_code' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $user = $this->auth->completeLogin(
                identifier: $identifier,
                code: $validated['code'],
                businessCode: $validated['business_code'] ?? $request->session()->get('login.business_code'),
                name: $validated['name'] ?? $request->session()->get('login.name'),
            );
        } catch (SupabaseAuthException $e) {
            return back()->withInput($request->only('code', 'business_code', 'name'))->withErrors([
                'code' => $e->getMessage(),
            ]);
        }

        $request->session()->forget(['login.identifier', 'login.business_code', 'login.name']);

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Welcome back, '.$user->name.'.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->auth->logout();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }
}
