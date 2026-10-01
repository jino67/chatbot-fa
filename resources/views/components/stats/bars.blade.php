@props(['rows', 'empty' => 'Pas encore de données sur cette période.', 'unit' => ''])
@php
    $rows = collect($rows)->values();
    $max = max(1, (int) $rows->max('value'));
@endphp
@if ($rows->isEmpty())
    <p class="px-6 py-8 text-center text-sm text-slate-500">{{ $empty }}</p>
@else
    <ul class="space-y-3 px-6 py-5">
        @foreach ($rows as $row)
            <li>
                <div class="flex items-baseline justify-between gap-3 text-sm">
                    <span class="min-w-0 truncate text-slate-800" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                    <span class="shrink-0 tabular-nums text-slate-600">
                        <strong class="font-semibold text-brand-950">{{ \App\Support\StatsFormat::number($row['value']) }}</strong>{{ $unit ? ' '.$unit : '' }}
                        @if (! empty($row['hint'])) <span class="ml-1 text-xs text-slate-500">{{ $row['hint'] }}</span> @endif
                    </span>
                </div>
                <div class="mt-1.5 h-1.5 rounded-full bg-slate-100" aria-hidden="true">
                    <div class="h-1.5 rounded-full bg-brand-500" style="width: {{ max(2, round(100 * $row['value'] / $max)) }}%"></div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
