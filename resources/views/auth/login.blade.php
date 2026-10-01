@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Sign in to your laundry</h1>
        <p class="mt-1 text-sm text-slate-500">
            Pick whichever is easier. Both take you to the same place.
        </p>

        @include('partials.flash')

        @if ($googleEnabled)
            <div class="mt-6">
                <a href="{{ route('login.google') }}"
                   class="flex w-full items-center justify-center gap-3 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.57c2.08-1.92 3.28-4.74 3.28-8.09Z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.76c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"/>
                        <path fill="#FBBC05" d="M5.84 14.11a6.6 6.6 0 0 1 0-4.22V7.05H2.18a11 11 0 0 0 0 9.9l3.66-2.84Z"/>
                        <path fill="#EA4335" d="M12 4.75c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 1.46 14.97.5 12 .5A11 11 0 0 0 2.18 7.05l3.66 2.84c.87-2.6 3.3-4.14 6.16-4.14Z"/>
                    </svg>
                    Continue with Google
                </a>

                @if ($passwordEnabled)
                    <div class="my-6 flex items-center gap-3">
                        <span class="h-px flex-1 bg-slate-200"></span>
                        <span class="text-xs font-medium uppercase tracking-wide text-slate-400">or</span>
                        <span class="h-px flex-1 bg-slate-200"></span>
                    </div>
                @endif
            </div>
        @endif

        @if ($passwordEnabled)
            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                    <input id="email"
                           name="email"
                           type="email"
                           inputmode="email"
                           autocomplete="username"
                           required
                           autofocus
                           value="{{ old('email') }}"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                    <input id="password"
                           name="password"
                           type="password"
                           autocomplete="current-password"
                           required
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                </div>

                <button type="submit"
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Sign in
                </button>
            </form>
        @endif

        @unless ($googleEnabled || $passwordEnabled)
            <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <p class="font-medium">Sign-in is not configured yet.</p>
                <p class="mt-1">
                    Add <code class="font-mono">GOOGLE_CLIENT_ID</code> and
                    <code class="font-mono">GOOGLE_CLIENT_SECRET</code> to <code class="font-mono">.env</code>,
                    then run <code class="font-mono">php artisan config:clear</code>.
                </p>
            </div>
        @endunless
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">
        New customer?
        @if ($registrationOpen)
            <a href="{{ route('register') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Create an account</a>
        @else
            <span>Ask your laundry for an invitation.</span>
        @endif
    </p>

    <p class="mt-2 text-center text-xs text-slate-400">
        Staff and owner accounts are created by your laundry. Sign in with Google using the
        mobile number they registered.
    </p>
@endsection
