@extends('layouts.guest')

@section('title', 'Create account')

@section('content')
    <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Create your account</h1>
        <p class="mt-1 text-sm text-slate-500">
            This creates a customer account for one laundry.
        </p>

        @include('partials.flash')

        @if ($googleEnabled)
            <a href="{{ route('login.google') }}"
               class="mt-6 flex w-full items-center justify-center gap-3 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.57c2.08-1.92 3.28-4.74 3.28-8.09Z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.76c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"/>
                    <path fill="#FBBC05" d="M5.84 14.11a6.6 6.6 0 0 1 0-4.22V7.05H2.18a11 11 0 0 0 0 9.9l3.66-2.84Z"/>
                    <path fill="#EA4335" d="M12 4.75c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 1.46 14.97.5 12 .5A11 11 0 0 0 2.18 7.05l3.66 2.84c.87-2.6 3.3-4.14 6.16-4.14Z"/>
                </svg>
                Continue with Google
            </a>

            <div class="my-6 flex items-center gap-3">
                <span class="h-px flex-1 bg-slate-200"></span>
                <span class="text-xs font-medium uppercase tracking-wide text-slate-400">or use a password</span>
                <span class="h-px flex-1 bg-slate-200"></span>
            </div>
        @endif

        <form method="POST" action="{{ route('register.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700">Your name</label>
                <input id="name" name="name" type="text" required autofocus autocomplete="name"
                       maxlength="120" value="{{ old('name') }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
            </div>

            <div>
                <label for="mobile" class="block text-sm font-medium text-slate-700">Mobile number</label>
                <input id="mobile" name="mobile" type="tel" required autocomplete="tel"
                       maxlength="25" value="{{ old('mobile') }}" placeholder="98765 43210"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                <p class="mt-1 text-xs text-slate-500">
                    This is how the laundry reaches you about your order.
                </p>
            </div>

            <div>
                <label for="business_code" class="block text-sm font-medium text-slate-700">{{ config('signin.business_code.label') }}</label>
                <input id="business_code" name="business_code" type="text" required
                       maxlength="40" value="{{ old('business_code') }}"
                       placeholder="{{ config('signin.business_code.placeholder') }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                <p class="mt-1 text-xs text-slate-500">
                    Printed on your bill or collection slip. It tells us which laundry you are joining.
                </p>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                <input id="email" name="email" type="email" required autocomplete="username"
                       maxlength="255" value="{{ old('email') }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                <input id="password" name="password" type="password" required
                       minlength="{{ $minLength }}" autocomplete="new-password"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                <p class="mt-1 text-xs text-slate-500">
                    At least {{ $minLength }} characters. A short phrase you will not reuse anywhere else works well.
                </p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                       minlength="{{ $minLength }}" autocomplete="new-password"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Create account
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">
        Already have an account? <a href="{{ route('login') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Sign in</a>
    </p>

    <p class="mt-2 text-center text-xs text-slate-400">
        Staff and owner accounts are created by your laundry owner, not here.
    </p>
@endsection
