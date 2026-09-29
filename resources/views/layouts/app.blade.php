<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Laundry') &middot; {{ config('app.name', 'Laundry Manager') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-800 antialiased">
<div class="min-h-full lg:flex">

    {{-- Sidebar: only the areas this role actually has access to. --}}
    @auth
        <aside class="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-white lg:flex">
            <div class="flex h-16 items-center gap-2 border-b border-slate-200 px-5">
                <span class="grid h-9 w-9 place-items-center rounded-lg bg-indigo-600 text-sm font-semibold text-white">
                    {{ Str::upper(Str::substr(auth()->user()->business?->business_name ?? 'L', 0, 1)) }}
                </span>
                <div class="leading-tight">
                    <p class="text-sm font-semibold text-slate-900">{{ auth()->user()->business?->business_name ?? 'Platform' }}</p>
                    <p class="text-xs text-slate-500">{{ auth()->user()->role->label() }}</p>
                </div>
            </div>

            <nav class="flex-1 space-y-1 p-3 text-sm">
                <a href="{{ route('dashboard') }}"
                   class="block rounded-lg px-3 py-2 font-medium {{ request()->routeIs('dashboard') ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50' }}">
                    Dashboard
                </a>
                <a href="{{ route('orders.index') }}"
                   class="block rounded-lg px-3 py-2 font-medium {{ request()->routeIs('orders.*') ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50' }}">
                    Orders
                </a>
            </nav>

            <div class="border-t border-slate-200 p-4 text-sm">
                <p class="font-medium text-slate-900">{{ auth()->user()->name }}</p>
                <p class="text-xs text-slate-500">{{ auth()->user()->mobile }}</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">
                        Sign out
                    </button>
                </form>
            </div>
        </aside>
    @endauth

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 lg:px-8">
            <h1 class="text-lg font-semibold text-slate-900">@yield('heading', View::getSection('title', 'Dashboard'))</h1>

            <div class="text-right text-sm">
                <p class="font-medium text-slate-900">{{ auth()->user()->name ?? '' }}</p>
                <p class="text-xs text-slate-500">{{ auth()->user()?->branch?->name ?? auth()->user()?->role?->label() }}</p>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-8">
            @include('partials.flash')

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
