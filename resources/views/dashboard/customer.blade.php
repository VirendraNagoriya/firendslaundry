@extends('layouts.app')

@section('title', 'My laundry')
@section('heading', auth()->user()->business?->business_name ?? 'My laundry')

@section('content')
    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Orders placed" :value="number_format($summary['orders']['total'])" />
        <x-stat label="In progress" :value="number_format($summary['orders']['open'])" tone="amber" />
        <x-stat label="Amount due" :value="'Rs. '.number_format($summary['money']['outstanding'], 2)" :tone="$summary['money']['outstanding'] > 0 ? 'rose' : 'emerald'" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div>
            @include('partials.orders-table', ['orders' => $recent, 'title' => 'Your orders'])
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Book a collection</h3>
            <p class="mt-1 text-sm text-slate-600">
                Call {{ auth()->user()->business?->mobile ?? 'your laundry' }} and they will collect
                from you. Each service is priced per kilogram or per piece; the final bill uses the
                weight measured at the branch.
            </p>

            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($services as $service)
                    <li class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <span class="text-slate-700">{{ $service->name }}</span>
                        <span class="font-medium text-slate-900">
                            Rs. {{ number_format((float) $service->rate, 2) }}
                            <span class="text-xs font-normal text-slate-500">/ {{ $service->pricing_type->label() }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection
