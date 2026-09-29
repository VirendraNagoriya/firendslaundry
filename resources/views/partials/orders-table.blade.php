@php
    use App\Enums\OrderStatus;

    $badges = [
        'COMPLETED' => 'bg-emerald-100 text-emerald-800',
        'CANCELLED' => 'bg-rose-100 text-rose-800',
        'ORDER_CREATED' => 'bg-slate-100 text-slate-700',
        'PICKED_UP' => 'bg-amber-100 text-amber-800',
    ];
@endphp

<div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
        <h3 class="text-sm font-semibold text-slate-900">{{ $title ?? 'Orders' }}</h3>
        <a href="{{ route('orders.index') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-500">See all</a>
    </div>

    @if ($orders->isEmpty())
        <p class="px-5 py-10 text-center text-sm text-slate-500">No orders in this period.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-2 font-medium">Order</th>
                        <th class="px-5 py-2 font-medium">Customer</th>
                        <th class="px-5 py-2 font-medium">Status</th>
                        <th class="px-5 py-2 text-right font-medium">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($orders as $order)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <a href="{{ route('orders.show', $order) }}" class="font-medium text-indigo-600 hover:text-indigo-500">
                                    {{ $order->order_number }}
                                </a>
                                <p class="text-xs text-slate-500">{{ $order->branch?->name }}</p>
                            </td>
                            <td class="px-5 py-3 text-slate-700">{{ $order->customer?->name ?? 'Walk-in' }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded-full px-2 py-1 text-xs font-medium {{ $badges[$order->order_status->value] ?? 'bg-indigo-100 text-indigo-800' }}">
                                    {{ $order->order_status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right font-medium text-slate-900">Rs. {{ number_format((float) $order->total_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (method_exists($orders, 'links'))
            <div class="border-t border-slate-200 px-5 py-3">{{ $orders->links() }}</div>
        @endif
    @endif
</div>
