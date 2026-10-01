@props(['series', 'key' => 'sessions', 'label' => 'visites', 'color' => '#2340d9'])
@php
    $series = array_values($series);
    $n = count($series);
    $max = max(1, $n ? max(array_column($series, $key)) : 1);
    $left = 40; $right = 712; $top = 12; $base = 176;
    $step = $n > 0 ? ($right - $left) / $n : 1;
    $bar = max(1.5, $step * 0.72);
    $marks = $n > 1 ? [0, intdiv($n - 1, 2), $n - 1] : [0];
@endphp
<svg viewBox="0 0 720 204" class="h-auto w-full" role="img" aria-label="Évolution quotidienne : {{ $label }}">
    @foreach ([0, 0.5, 1] as $g)
        @php $y = $base - ($base - $top) * $g; @endphp
        <line x1="{{ $left }}" x2="{{ $right }}" y1="{{ $y }}" y2="{{ $y }}" stroke="currentColor" class="text-slate-200" stroke-width="1" @if ($g > 0) stroke-dasharray="3 4" @endif />
        <text x="{{ $left - 6 }}" y="{{ $y + 4 }}" text-anchor="end" class="fill-slate-400" font-size="11">{{ \App\Support\StatsFormat::number(round($max * $g)) }}</text>
    @endforeach
    @foreach ($series as $i => $day)
        @php
            $height = ($base - $top) * ($day[$key] / $max);
            $x = $left + $i * $step + ($step - $bar) / 2;
        @endphp
        <rect x="{{ round($x, 2) }}" y="{{ round($base - $height, 2) }}" width="{{ round($bar, 2) }}" height="{{ round(max($day[$key] > 0 ? 1.5 : 0, $height), 2) }}" rx="2" fill="{{ $color }}" opacity="{{ $day[$key] > 0 ? 0.9 : 0.25 }}">
            <title>{{ $day['label'] }} : {{ \App\Support\StatsFormat::number($day[$key]) }} {{ $label }}</title>
        </rect>
    @endforeach
    @foreach ($marks as $i)
        <text x="{{ round($left + $i * $step + $step / 2, 1) }}" y="198" text-anchor="{{ $i === 0 && $n > 1 ? 'start' : ($i === $n - 1 && $n > 1 ? 'end' : 'middle') }}" class="fill-slate-500" font-size="11">{{ $series[$i]['label'] ?? '' }}</text>
    @endforeach
</svg>
