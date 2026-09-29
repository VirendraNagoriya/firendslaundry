@extends('layouts.app')

@section('title', 'Sign in')
@section('heading', 'Sign in')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <h2 class="text-xl font-semibold text-slate-900">Sign in to your laundry</h2>
            <p class="mt-1 text-sm text-slate-500">
                We will send a one-time code. No password to remember.
            </p>

            @unless ($configured)
                <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p class="font-medium">Sign-in is not configured yet.</p>
                    <p class="mt-1">
                        Add <code class="font-mono">SUPABASE_URL</code>, <code class="font-mono">SUPABASE_ANON_KEY</code>
                        and set <code class="font-mono">SUPABASE_AUTH_ENABLED=true</code> in
                        <code class="font-mono">.env</code>, then run
                        <code class="font-mono">php artisan config:clear</code>.
                    </p>
                </div>
            @endunless

            <form method="POST" action="{{ route('login.send') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="identifier" class="block text-sm font-medium text-slate-700">Your {{ Str::lower($identifierLabel) }}</label>
                    <input id="identifier"
                           name="identifier"
                           type="{{ $identifierType }}"
                           inputmode="{{ $identifierMode }}"
                           autocomplete="tel"
                           required
                           autofocus
                           value="{{ old('identifier') }}"
                           placeholder="98765 43210"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                </div>

                <div>
                    <label for="business_code" class="block text-sm font-medium text-slate-700">
                        Laundry code <span class="font-normal text-slate-400">(only if you are new)</span>
                    </label>
                    <input id="business_code"
                           name="business_code"
                           type="text"
                           value="{{ old('business_code') }}"
                           placeholder="ABC"
                           maxlength="40"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                    <p class="mt-1 text-xs text-slate-500">
                        Printed on your bill or collection slip. It tells us which laundry you are signing in to.
                    </p>
                </div>

                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">
                        Your name <span class="font-normal text-slate-400">(only if you are new)</span>
                    </label>
                    <input id="name"
                           name="name"
                           type="text"
                           value="{{ old('name') }}"
                           maxlength="120"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                </div>

                <button type="submit"
                        @disabled(! $configured)
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                    Send login code
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-xs text-slate-500">
            Staff accounts are created by your laundry owner. Use the mobile number they registered.
        </p>
    </div>
@endsection
