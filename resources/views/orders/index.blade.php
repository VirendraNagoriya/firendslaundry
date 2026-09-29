@extends('layouts.app')

@section('title', 'Orders')
@section('heading', 'Orders')

@section('content')
    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label for="q" class="block text-xs font-medium text-slate-600">Search</label>
            <input id="q" type="search" name="q" value="{{ request('q') }}" placeholder="Order number, name or mobile"
                   class="mt-1 w-64 rounded-lg border border-slate-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
        </div>

        <div>
            <label for="status" class="block text-xs font-medium text-slate-600">Status</label>
            <select id="status" name="status"
                    class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                <option value="">All</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>

        @if ($branches->isNotEmpty())
            <div>
                <label for="branch" class="block text-xs font-medium text-slate-600">Branch</label>
                <select id="branch" name="branch"
                        class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                    <option value="">All</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) request('branch') === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <button class="rounded-lg bg-slate-900 px-4 py-1.5 font-medium text-white hover:bg-slate-700">Filter</button>
        <a href="{{ route('orders.index') }}" class="px-2 py-1.5 text-slate-500 hover:text-slate-700">Reset</a>
    </form>

    @include('partials.orders-table', ['orders' => $orders, 'title' => 'Orders'])
@endsection
