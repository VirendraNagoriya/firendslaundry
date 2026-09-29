@extends('layouts.app')

@section('title', 'Branch')
@section('heading', auth()->user()->branch?->name ?? 'Branch')

@section('content')
    @include('partials.date-filter')

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Orders at this branch" :value="number_format($summary['orders']['total'])" />
        <x-stat label="Still open" :value="number_format($summary['orders']['open'])" tone="amber" />
        <x-stat label="Awaiting weighing" :value="number_format($summary['orders']['awaiting_weighing'])" tone="indigo" hint="Picked up, not yet received" />
        <x-stat label="Outstanding" :value="'Rs. '.number_format($summary['money']['outstanding'], 2)" :tone="$summary['money']['outstanding'] > 0 ? 'rose' : 'emerald'" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @include('partials.orders-table', ['orders' => $recent, 'title' => 'Latest at this branch'])
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">On the bench</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach (\App\Enums\OrderStatus::cases() as $status)
                    @continue($statusCounts[$status->value] === 0)
                    <li class="flex items-center justify-between">
                        <span class="text-slate-600">{{ $status->label() }}</span>
                        <span class="font-semibold text-slate-900">{{ $statusCounts[$status->value] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection
