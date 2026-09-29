@extends('layouts.app')

@section('title', 'My work')
@section('heading', 'My work')

@section('content')
    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Jobs waiting" :value="$myJobs->count()" tone="indigo" />
        <x-stat label="Completed this month" :value="number_format($summary['orders']['completed'])" tone="emerald" />
        <x-stat label="Still open" :value="number_format($summary['orders']['open'])" tone="amber" />
    </div>

    <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-sm font-semibold text-slate-900">Next jobs</h3>
        </div>

        @if ($myJobs->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-500">Nothing assigned to you right now.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($myJobs as $job)
                    <li class="px-5 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <a href="{{ route('orders.show', $job) }}" class="font-medium text-indigo-600 hover:text-indigo-500">
                                    {{ $job->order_number }}
                                </a>
                                <p class="mt-1 text-sm text-slate-700">{{ $job->customer?->name ?? 'Walk-in customer' }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $job->address?->oneLine() ?? $job->branch?->name }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full bg-indigo-100 px-2 py-1 text-xs font-medium text-indigo-800">
                                {{ $job->order_status->label() }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
