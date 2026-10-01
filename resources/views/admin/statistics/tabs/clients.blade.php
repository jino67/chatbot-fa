@php
    $tone = ['good' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'ok' => 'bg-sky-50 text-sky-700 ring-sky-600/20', 'warn' => 'bg-accent-50 text-accent-700 ring-accent-500/40', 'bad' => 'bg-red-50 text-red-700 ring-red-600/20'];
    $daily = collect($daily)->map(fn ($d) => ['date' => $d['label'], 'label' => $d['label'], 'sessions' => $d['count'], 'visitors' => $d['count'], 'pageviews' => $d['count']])->all();
    $adoptionRows = collect($adoption)->map(fn ($a) => ['label' => $a['label'], 'value' => $a['workspaces'], 'hint' => \App\Support\StatsFormat::percent($a['percent']).' des clients actifs'])->all();
@endphp

@include('admin.statistics._insights', ['insights' => $insights, 'query' => $query])

<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="surface p-5"><p class="text-sm text-slate-600">Actifs aujourd'hui</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $active['dau'] }}</p><p class="mt-1 text-xs text-slate-500">sur {{ $active['workspaces'] }} espaces clients</p></div>
    <div class="surface p-5"><p class="text-sm text-slate-600">Actifs cette semaine</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $active['wau'] }}</p><p class="mt-1 text-xs text-slate-500">7 derniers jours</p></div>
    <div class="surface p-5"><p class="text-sm text-slate-600">Actifs ce mois</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $active['mau'] }}</p><p class="mt-1 text-xs text-slate-500">30 derniers jours</p></div>
    <div class="surface p-5"><p class="text-sm text-slate-600">Régularité</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::percent($active['stickiness']) }}</p><p class="mt-1 text-xs text-slate-500">part du mois où un client actif revient chaque jour</p></div>
</div>

<x-stats.card title="Espaces actifs jour après jour" hint="Nombre d'espaces clients différents qui se sont connectés chaque jour.">
    <div class="px-6 py-5"><x-stats.daily :series="$daily" key="sessions" label="espaces actifs" color="#0b1340" /></div>
</x-stats.card>

<div class="grid gap-6 lg:grid-cols-2">
    <x-stats.card title="Les nouveaux comptes avancent-ils ?" hint="Parmi les comptes créés sur la période, jusqu'où ils sont allés. Calculé à partir de ce qui existe vraiment dans leur espace.">
        <x-stats.funnel :steps="$activation" />
    </x-stats.card>

    <x-stats.card title="Fonctions utilisées" hint="Part des clients actifs ce mois qui se sont servis de chaque fonction.">
        <x-stats.bars :rows="$adoptionRows" unit="client(s)" />
    </x-stats.card>
</div>

@if (count($risk))
    <x-stats.card title="Clients qui ont décroché" hint="Compte de plus d'une semaine, sans visite depuis plus de 14 jours. Un message ou un appel peut les faire revenir.">
        <ul class="divide-y divide-slate-100">
            @foreach ($risk as $client)
                <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm">
                    <div>
                        <a href="{{ route('admin.workspaces.show', $client['id']) }}" class="font-semibold text-brand-800 hover:underline">{{ $client['name'] }}</a>
                        <span class="ml-1 text-xs text-slate-500">offre {{ $client['plan'] }}</span>
                    </div>
                    <span class="text-slate-600">{{ $client['days_since'] === null ? 'Ne s\'est jamais connecté depuis la mesure' : 'Dernière visite il y a '.$client['days_since'].' jours' }}</span>
                </li>
            @endforeach
        </ul>
    </x-stats.card>
@endif

<x-stats.card title="Santé de chaque client" hint="Note sur 100 : visites récentes (40), régularité (30), fonctions utilisées (20), vrais clients qui écrivent (10).">
    @if (! count($rows))
        <p class="px-6 py-10 text-center text-sm text-slate-500">Aucun espace client pour l'instant.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-left text-xs text-slate-500">
                        <th class="px-6 py-3 font-medium">Espace</th>
                        <th class="px-3 py-3 font-medium">Offre</th>
                        <th class="px-3 py-3 text-right font-medium">Visites</th>
                        <th class="px-3 py-3 text-right font-medium">Jours actifs</th>
                        <th class="px-3 py-3 text-right font-medium">Fonctions</th>
                        <th class="px-3 py-3 font-medium">Dernière visite</th>
                        <th class="px-6 py-3 text-right font-medium">Santé</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $client)
                        <tr>
                            <td class="px-6 py-3"><a href="{{ route('admin.workspaces.show', $client['id']) }}" class="font-medium text-brand-800 hover:underline">{{ $client['name'] }}</a>@if ($client['suspended']) <span class="ml-1 text-xs text-red-700">suspendu</span> @endif @if ($client['live']) <span class="ml-1 text-xs text-emerald-700">en ligne</span> @endif</td>
                            <td class="px-3 py-3 text-slate-600">{{ $client['plan'] }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $client['sessions'] }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $client['active_days'] }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $client['features'] }}</td>
                            <td class="px-3 py-3 text-slate-600">{{ $client['last_seen'] ? $client['last_seen']->locale('fr')->diffForHumans() : 'jamais' }}</td>
                            <td class="px-6 py-3 text-right"><span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $tone[$client['health']['tone']] }}">{{ $client['health']['score'] }} : {{ $client['health']['label'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-stats.card>

@if (count($cohorts))
    <x-stats.card title="Les clients reviennent-ils ?" hint="Chaque ligne est une semaine d'inscription. Les colonnes disent quelle part de ces comptes s'est reconnectée la semaine même (0), la suivante (1), et ainsi de suite.">
        <div class="overflow-x-auto px-6 py-5">
            <table class="w-full text-center text-xs">
                <thead>
                    <tr class="text-slate-500">
                        <th class="py-2 text-left font-medium">Semaine du</th>
                        <th class="px-2 py-2 font-medium">Comptes</th>
                        @for ($w = 0; $w < 8; $w++) <th class="px-2 py-2 font-medium">Sem. {{ $w }}</th> @endfor
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cohorts as $cohort)
                        <tr>
                            <td class="py-1.5 text-left text-slate-700">{{ $cohort['week'] }}</td>
                            <td class="px-2 py-1.5 tabular-nums text-slate-700">{{ $cohort['size'] }}</td>
                            @foreach ($cohort['retention'] as $value)
                                <td class="px-1 py-1">
                                    @if ($value === null) <span class="block h-7"></span>
                                    @else <span class="flex h-7 items-center justify-center rounded-md tabular-nums {{ $value >= 50 ? 'text-white' : 'text-brand-950' }}" style="background-color: rgba(35, 64, 217, {{ round(0.08 + 0.92 * $value / 100, 2) }})">{{ round($value) }}</span> @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-stats.card>
@endif

@if ($heat['total'] > 0)
    <x-stats.card title="Quand vos clients se connectent" hint="Les créneaux où l'équipe doit être joignable : un client bloqué à ce moment-là appelle tout de suite.">
        <x-stats.heatmap :heat="$heat" unit="visites" />
    </x-stats.card>
@endif
