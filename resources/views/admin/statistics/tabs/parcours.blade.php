@php
    $transitions = collect($journeys['transitions'])->map(fn ($t) => ['label' => $t['from'].' vers '.$t['to'], 'value' => $t['count']])->all();
    $depth = collect($journeys['depth'])->map(fn ($n, $label) => ['label' => $label, 'value' => $n])->values()->all();
    $duration = collect($journeys['duration'])->map(fn ($n, $label) => ['label' => $label, 'value' => $n])->values()->all();
    $entryRows = collect($entries)->map(fn ($r) => ['label' => $r['label'], 'value' => $r['sessions'], 'hint' => \App\Support\StatsFormat::percent($r['bounce_rate']).' repartent sans rien faire'])->all();
@endphp

<x-stats.card title="De la visite à l'inscription" hint="Chaque barre garde les personnes de la précédente qui sont allées plus loin. L'écart le plus grand est l'endroit où agir.">
    <x-stats.funnel :steps="$funnel" />
</x-stats.card>

<div class="grid gap-6 lg:grid-cols-2">
    <x-stats.card title="Par où ils entrent" hint="La première page de chaque visite.">
        <x-stats.bars :rows="$entryRows" unit="visites" />
    </x-stats.card>
    <x-stats.card title="Les passages d'une page à l'autre" hint="Calculés sur les 20 000 pages vues les plus récentes de la période.">
        <x-stats.bars :rows="$transitions" unit="fois" empty="Pas encore de visite sur plusieurs pages." />
    </x-stats.card>
    <x-stats.card title="Pages lues par visite"><x-stats.bars :rows="$depth" unit="visites" /></x-stats.card>
    <x-stats.card title="Temps passé par visite" hint="Temps réellement actif, onglet ouvert et au premier plan."><x-stats.bars :rows="$duration" unit="visites" /></x-stats.card>
</div>
