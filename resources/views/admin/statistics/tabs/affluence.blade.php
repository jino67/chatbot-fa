@php
    $hours = collect(range(0, 23))->map(fn ($h) => ['label' => sprintf('%02d h', $h), 'value' => $heat['by_hour'][$h]])->all();
    $weekdays = collect(\App\Services\Analytics\Stats::DAYS)->map(fn ($d, $i) => ['label' => $d, 'value' => $heat['by_day'][$i]])->all();
@endphp

@if ($heat['total'] === 0)
    <div class="surface px-6 py-12 text-center text-sm text-slate-600">Aucune visite sur cette période. Dès que des personnes visiteront le site, les heures d'affluence apparaîtront ici.</div>
@else
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="surface p-5"><p class="text-sm text-slate-600">Heure la plus chargée</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $heat['peak_hour'] }} h</p><p class="mt-1 text-xs text-slate-500">à {{ $heat['peak_hour'] }} h, toutes journées confondues</p></div>
        <div class="surface p-5"><p class="text-sm text-slate-600">Jour le plus chargé</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $heat['peak_day'] }}</p><p class="mt-1 text-xs text-slate-500">{{ \App\Support\StatsFormat::number(max($heat['by_day'])) }} visites sur la période</p></div>
        <div class="surface p-5"><p class="text-sm text-slate-600">Créneau record</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $heat['peak']['day'] }}, {{ $heat['peak']['hour'] }} h</p><p class="mt-1 text-xs text-slate-500">{{ $heat['peak']['count'] }} visites sur ce créneau</p></div>
    </div>

    <x-stats.card title="Jour et heure" hint="Chaque case est une heure d'un jour de la semaine : plus elle est foncée, plus il y a de visites. Heures de la plateforme.">
        <x-stats.heatmap :heat="$heat" />
    </x-stats.card>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-stats.card title="Par jour de la semaine"><x-stats.bars :rows="$weekdays" unit="visites" /></x-stats.card>
        <x-stats.card title="Par heure de la journée"><x-stats.bars :rows="$hours" unit="visites" /></x-stats.card>
    </div>

    <x-stats.card title="Visites jour après jour">
        <div class="px-6 py-5"><x-stats.daily :series="$daily" key="sessions" label="visites" /></div>
    </x-stats.card>
@endif
