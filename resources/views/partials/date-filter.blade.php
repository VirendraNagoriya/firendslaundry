<form method="GET" class="mb-6 flex flex-wrap items-end gap-3 text-sm">
    <div>
        <label for="from" class="block text-xs font-medium text-slate-600">From</label>
        <input id="from" type="date" name="from" value="{{ $from->toDateString() }}"
               class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
    </div>

    <div>
        <label for="to" class="block text-xs font-medium text-slate-600">To</label>
        <input id="to" type="date" name="to" value="{{ $to->toDateString() }}"
               class="mt-1 rounded-lg border border-slate-300 px-3 py-1.5 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
    </div>

    <button class="rounded-lg bg-slate-900 px-4 py-1.5 font-medium text-white hover:bg-slate-700">Apply</button>
    <a href="{{ route('dashboard') }}" class="px-2 py-1.5 text-slate-500 hover:text-slate-700">Reset</a>
</form>
