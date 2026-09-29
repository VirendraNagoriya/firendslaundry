@extends('layouts.app')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
    @include('partials.date-filter')

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Orders" :value="number_format($summary['orders']['total'])" :hint="number_format($summary['orders']['open']).' still open'" />
        <x-stat label="Booked value" :value="'Rs. '.number_format($summary['money']['booked'], 2)" tone="indigo" />
        <x-stat label="Collected" :value="'Rs. '.number_format($summary['money']['collected'], 2)" tone="emerald" />
        <x-stat label="Outstanding" :value="'Rs. '.number_format($summary['money']['outstanding'], 2)" :tone="$summary['money']['outstanding'] > 0 ? 'rose' : 'emerald'" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @include('partials.orders-table', ['orders' => $recent, 'title' => 'Latest orders'])
        </div>

        <div class="space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">Where the orders are</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($statusCounts as $value => $count)
                        @continue($count === 0)
                        <li class="flex items-center justify-between">
                            <span class="text-slate-600">{{ \App\Enums\OrderStatus::from($value)->label() }}</span>
                            <span class="font-semibold text-slate-900">{{ $count }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">Cash collected by method</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($collections as $method => $total)
                        <li class="flex items-center justify-between">
                            <span class="text-slate-600">{{ $method }}</span>
                            <span class="font-semibold text-slate-900">Rs. {{ number_format($total, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endsection
