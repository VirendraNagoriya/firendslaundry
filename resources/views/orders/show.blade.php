@extends('layouts.app')

@section('title', $order->order_number)
@section('heading', $order->order_number)

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">

        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <span class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-medium text-indigo-800">
                            {{ $order->order_status->label() }}
                        </span>
                        <p class="mt-2 text-sm text-slate-600">
                            {{ $order->order_source->label() }} &middot; {{ $order->fulfilment_type->label() }}
                            &middot; {{ $order->created_at?->format('d M Y, h:i A') }}
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Amount due</p>
                        <p class="text-2xl font-semibold text-slate-900">Rs. {{ number_format($order->amountDue(), 2) }}</p>
                        <p class="text-xs text-slate-500">{{ $order->payment_status->label() }}</p>
                    </div>
                </div>

                @if ($order->isAwaitingActualWeight())
                    <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        Priced on the estimate. The final bill is recalculated once the laundry has weighed
                        each item.
                    </p>
                @endif
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Items</h3>
                </div>

                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 font-medium">Service</th>
                            <th class="px-5 py-2 text-right font-medium">Estimate</th>
                            <th class="px-5 py-2 text-right font-medium">Measured</th>
                            <th class="px-5 py-2 text-right font-medium">Rate</th>
                            <th class="px-5 py-2 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="px-5 py-3">
                                    <p class="font-medium text-slate-900">{{ $item->service?->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $item->service?->pricing_type->label() }}</p>
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600">
                                    {{ $item->service?->isPricedByWeight() ? $item->approx_weight.' KG' : $item->quantity.' pc' }}
                                </td>
                                <td class="px-5 py-3 text-right font-medium text-slate-900">
                                    {{ $item->service?->isPricedByWeight() ? ($item->actual_weight !== null ? $item->actual_weight.' KG' : '—') : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right text-slate-600">Rs. {{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="px-5 py-3 text-right font-medium text-slate-900">Rs. {{ number_format((float) $item->total_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 text-sm">
                        <tr>
                            <td colspan="4" class="px-5 py-2 text-right text-slate-600">Subtotal</td>
                            <td class="px-5 py-2 text-right font-medium">Rs. {{ number_format((float) $order->subtotal, 2) }}</td>
                        </tr>
                        @if ((float) $order->pickup_charge > 0)
                            <tr>
                                <td colspan="4" class="px-5 py-2 text-right text-slate-600">Pickup</td>
                                <td class="px-5 py-2 text-right">Rs. {{ number_format((float) $order->pickup_charge, 2) }}</td>
                            </tr>
                        @endif
                        @if ((float) $order->delivery_charge > 0)
                            <tr>
                                <td colspan="4" class="px-5 py-2 text-right text-slate-600">Delivery</td>
                                <td class="px-5 py-2 text-right">Rs. {{ number_format((float) $order->delivery_charge, 2) }}</td>
                            </tr>
                        @endif
                        <tr class="font-semibold">
                            <td colspan="4" class="px-5 py-3 text-right text-slate-900">Total</td>
                            <td class="px-5 py-3 text-right text-slate-900">Rs. {{ number_format((float) $order->total_amount, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">History</h3>
                </div>

                <ol class="space-y-0 px-5 py-4">
                    @foreach ($order->statusHistory as $entry)
                        <li class="relative border-l border-slate-200 pb-5 pl-6 last:border-transparent last:pb-0">
                            <span class="absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
                            <p class="text-sm font-medium text-slate-900">{{ $entry->new_status->label() }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $entry->created_at?->format('d M Y, h:i A') }}
                                @if ($entry->changedBy) &middot; {{ $entry->changedBy->name }} @endif
                            </p>
                            @if ($entry->note)
                                <p class="mt-1 text-sm text-slate-600">{{ $entry->note }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        <div class="space-y-6">
            @if ($canChangeStatus && $nextStatuses !== [])
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-slate-900">Move this order on</h3>
                    <p class="mt-1 text-xs text-slate-500">Only steps the lifecycle allows are offered.</p>

                    <form method="POST" action="{{ route('orders.status', $order) }}" class="mt-4 space-y-3">
                        @csrf
                        @method('PATCH')

                        <select name="status" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                            @foreach ($nextStatuses as $next)
                                <option value="{{ $next->value }}">{{ $next->label() }}</option>
                            @endforeach
                        </select>

                        <input type="text" name="note" placeholder="Note (optional)"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">

                        <button class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                            Update status
                        </button>
                    </form>
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm text-sm">
                <h3 class="text-sm font-semibold text-slate-900">Customer</h3>
                <p class="mt-2 text-slate-700">{{ $order->customer?->name ?? 'Walk-in customer' }}</p>
                @if ($order->customer?->mobile)
                    <p class="text-slate-600">{{ $order->customer->mobile }}</p>
                @endif
                @if ($order->address)
                    <p class="mt-2 text-slate-600">{{ $order->address->oneLine() }}</p>
                @endif

                <h3 class="mt-5 text-sm font-semibold text-slate-900">Branch</h3>
                <p class="mt-2 text-slate-700">{{ $order->branch?->name }}</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm text-sm">
                <h3 class="text-sm font-semibold text-slate-900">Payments</h3>

                @if ($order->payments->isEmpty())
                    <p class="mt-2 text-slate-500">Nothing collected yet.</p>
                @else
                    <ul class="mt-3 space-y-2">
                        @foreach ($order->payments as $payment)
                            <li class="flex items-center justify-between border-b border-slate-100 pb-2">
                                <span class="text-slate-600">
                                    {{ $payment->method->label() }}
                                    <span class="text-xs text-slate-400">{{ $payment->collected_at?->format('d M, h:i A') }}</span>
                                </span>
                                <span class="font-medium text-slate-900">Rs. {{ number_format((float) $payment->amount, 2) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endsection
