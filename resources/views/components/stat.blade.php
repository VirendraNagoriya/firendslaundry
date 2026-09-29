@props(['label', 'value', 'hint' => null, 'tone' => 'slate'])

@php
    $tones = [
        'slate' => 'text-slate-900',
        'indigo' => 'text-indigo-700',
        'emerald' => 'text-emerald-700',
        'amber' => 'text-amber-700',
        'rose' => 'text-rose-700',
    ];
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
    <p class="mt-2 text-2xl font-semibold {{ $tones[$tone] ?? $tones['slate'] }}">{{ $value }}</p>

    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
