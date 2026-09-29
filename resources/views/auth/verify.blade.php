@extends('layouts.app')

@section('title', 'Enter your code')
@section('heading', 'Enter your code')

@section('content')
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <p class="text-sm text-slate-600">
                We sent a one-time code to
                <span class="font-semibold text-slate-900">{{ $identifier }}</span>.
                It expires in {{ $expiryMinutes }} minutes.
            </p>

            <form method="POST" action="{{ route('login.confirm') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="code" class="block text-sm font-medium text-slate-700">Login code</label>
                    <input id="code"
                           name="code"
                           type="text"
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           required
                           autofocus
                           maxlength="12"
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-center text-2xl font-semibold tracking-[0.4em] shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                </div>

                {{-- Kept visible so a person who guessed the wrong laundry
                     can correct it without starting over. --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="business_code" class="block text-xs font-medium text-slate-600">Laundry code</label>
                        <input id="business_code"
                               name="business_code"
                               type="text"
                               value="{{ old('business_code', $businessCode) }}"
                               maxlength="40"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm uppercase shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                    </div>
                    <div>
                        <label for="name" class="block text-xs font-medium text-slate-600">Your name</label>
                        <input id="name"
                               name="name"
                               type="text"
                               value="{{ old('name', $name) }}"
                               maxlength="120"
                               class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                    </div>
                </div>

                <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Sign in
                </button>
            </form>

            <form method="POST" action="{{ route('login.send') }}" class="mt-4 text-center">
                @csrf
                <input type="hidden" name="identifier" value="{{ $identifier }}">
                <button class="text-sm font-medium text-indigo-600 hover:text-indigo-500">Send a new code</button>
            </form>
        </div>
    </div>
@endsection
