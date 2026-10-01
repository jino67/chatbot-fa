@props(['steps'])
@php $steps = array_values($steps); @endphp
<ol class="space-y-3 px-6 py-5">
    @foreach ($steps as $i => $step)
        <li>
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                <span class="text-slate-800">{{ $step['label'] }}</span>
                <span class="tabular-nums text-slate-600">
                    <strong class="font-semibold text-brand-950">{{ \App\Support\StatsFormat::number($step['count']) }}</strong>
                    <span class="text-xs text-slate-500">({{ \App\Support\StatsFormat::percent($step['percent']) }} du départ{{ $i > 0 ? ', '.\App\Support\StatsFormat::percent($step['from_previous']).' de l\'étape d\'avant' : '' }})</span>
                </span>
            </div>
            <div class="mt-1.5 h-2.5 rounded-full bg-slate-100" aria-hidden="true">
                <div class="h-2.5 rounded-full bg-brand-500" style="width: {{ max($step['count'] > 0 ? 2 : 0, $step['percent']) }}%; opacity: {{ round(1 - $i * 0.1, 2) }}"></div>
            </div>
        </li>
    @endforeach
</ol>
