@extends('layouts.guest')

@section('title', 'Confirm your laundry')

@section('content')
    <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Which laundry is this?</h1>
        <p class="mt-1 text-sm text-slate-500">
            Google confirmed who you are. One more thing ties you to the right
            laundry, because you may use more than one.
        </p>

        @include('partials.flash')

        <div class="mt-6 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">
                {{ Str::upper(Str::substr($googleName ?: '?', 0, 1)) }}
            </span>
            <div class="min-w-0 leading-tight">
                <p class="truncate text-sm font-medium text-slate-900">{{ $googleName ?: 'Google account' }}</p>
                @if ($googleEmail)
                    <p class="truncate text-xs text-slate-500">{{ $googleEmail }}</p>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('login.google.setup.store') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="business_code" class="block text-sm font-medium text-slate-700">
                    {{ config('signin.business_code.label') }}
                </label>
                <input id="business_code"
                       name="business_code"
                       type="text"
                       required
                       autofocus
                       maxlength="40"
                       value="{{ old('business_code') }}"
                       placeholder="{{ config('signin.business_code.placeholder') }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                <p class="mt-1 text-xs text-slate-500">
                    Printed on your bill or collection slip.
                </p>
            </div>

            <div>
                <label for="mobile" class="block text-sm font-medium text-slate-700">Mobile number</label>
                <input id="mobile"
                       name="mobile"
                       type="tel"
                       required
                       maxlength="25"
                       value="{{ old('mobile') }}"
                       placeholder="98765 43210"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                <p class="mt-1 text-xs text-slate-500">
                    Use the number this laundry already has on file, so your orders
                    and your existing account are found.
                </p>
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700">
                    Your name <span class="font-normal text-slate-400">(if the laundry does not have it)</span>
                </label>
                <input id="name"
                       name="name"
                       type="text"
                       maxlength="120"
                       value="{{ old('name', $googleName) }}"
                       class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                Continue
            </button>
        </form>
    </div>

    <p class="mt-6 text-center text-xs text-slate-500">
        Staff or owner? Sign in with the mobile number your laundry registered,
        and your access is applied automatically.
    </p>

    <p class="mt-2 text-center text-xs">
        <a href="{{ route('login.google.cancel') }}" class="text-slate-400 hover:text-slate-600">Cancel and use a different account</a>
    </p>
@endsection
