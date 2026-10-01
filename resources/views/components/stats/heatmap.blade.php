@props(['heat', 'unit' => 'visites'])
@php
    $matrix = $heat['matrix'];
    $max = $heat['max'];
    $days = \App\Services\Analytics\Stats::DAYS;
@endphp
<div class="overflow-x-auto px-6 py-5">
    <div class="min-w-[680px]" role="table" aria-label="Affluence par jour et par heure">
        <div class="grid items-center gap-[3px]" style="grid-template-columns: 5.5rem repeat(24, minmax(0, 1fr));" role="row">
            <span></span>
            @for ($h = 0; $h < 24; $h++)
                <span class="text-center text-[10px] tabular-nums text-slate-500" role="columnheader">{{ $h % 3 === 0 ? $h.' h' : '' }}</span>
            @endfor
        </div>
        @foreach ($matrix as $d => $hours)
            <div class="mt-[3px] grid items-center gap-[3px]" style="grid-template-columns: 5.5rem repeat(24, minmax(0, 1fr));" role="row">
                <span class="pr-2 text-xs text-slate-600" role="rowheader">{{ $days[$d] }}</span>
                @foreach ($hours as $h => $count)
                    @php $alpha = $count > 0 ? round(0.14 + 0.86 * ($count / $max), 2) : 0; @endphp
                    <span role="cell" class="block h-6 rounded-[4px] {{ $count > 0 ? '' : 'bg-slate-100/70' }}"
                          style="{{ $count > 0 ? 'background-color: rgba(35, 64, 217, '.$alpha.')' : '' }}"
                          title="{{ $days[$d] }}, {{ $h }} h : {{ \App\Support\StatsFormat::number($count) }} {{ $unit }}"
                          aria-label="{{ $days[$d] }} {{ $h }} h : {{ $count }} {{ $unit }}"></span>
                @endforeach
            </div>
        @endforeach
        <div class="mt-3 flex items-center justify-end gap-2 text-[11px] text-slate-500">
            <span>Moins</span>
            @foreach ([0.14, 0.35, 0.55, 0.78, 1] as $a)
                <span class="block h-3 w-5 rounded-[3px]" style="background-color: rgba(35, 64, 217, {{ $a }})"></span>
            @endforeach
            <span>Plus</span>
        </div>
    </div>
</div>
