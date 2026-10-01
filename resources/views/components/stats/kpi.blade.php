@props(['item'])
@php
    $delta = $item['delta'];
    $up = $delta !== null && $delta > 0;
    $good = $delta !== null && $delta != 0 && (($item['good_when'] === 'up') === $up);
    $tone = $delta === null || $delta == 0 ? 'text-slate-500' : ($good ? 'text-emerald-700' : 'text-red-700');
@endphp
<div class="surface p-5">
    <p class="text-sm text-slate-600">{{ $item['label'] }}</p>
    <p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::value($item['value'], $item['format']) }}</p>
    <p class="mt-1 text-xs {{ $tone }}">
        @if ($delta === null)
            Rien à comparer sur la période d'avant
        @else
            {{ \App\Support\StatsFormat::delta($delta) }} <span class="text-slate-500">face à la période d'avant ({{ \App\Support\StatsFormat::value($item['previous'], $item['format']) }})</span>
        @endif
    </p>
</div>
