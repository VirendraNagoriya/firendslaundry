@extends('layouts.app')

@section('title', 'Platform')
@section('heading', 'Platform overview')

@section('content')
    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900">Businesses on the platform</h3>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Laundry</th>
                        <th class="px-4 py-2 font-medium">Code</th>
                        <th class="px-4 py-2 font-medium">Branches</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($businesses as $business)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $business->business_name }}</td>
                            <td class="px-4 py-3 font-mono text-slate-600">{{ $business->slug ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-700">
                                {{ $business->branchesUsed() }} of {{ $business->branch_limit }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-800">
                                    {{ $business->status }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">
        @include('partials.orders-table', ['orders' => $recent, 'title' => 'Latest orders across the platform'])
    </div>
@endsection
