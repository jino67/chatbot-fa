@php
    $query = fn (array $extra = []) => array_merge(['onglet' => $tab, 'jours' => $days, 'public' => $audience], $extra);
    $exportable = ['apercu' => 'jours', 'pages' => 'pages', 'clics' => 'clics', 'acquisition' => 'provenance', 'clients' => 'clients'];
    $pill = fn (bool $on) => $on
        ? 'bg-brand-600 text-white shadow-sm'
        : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-brand-50 hover:text-brand-800';
@endphp
<x-app-layout title="Statistiques | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Statistiques" subtitle="Qui visite, ce que chacun fait, à quelle heure, et comment vos clients se servent de la plateforme. Mesure interne : aucune adresse IP gardée, aucun service tiers.">
            <x-slot name="actions">
                @isset($exportable[$tab])
                    <a href="{{ route('admin.statistics.export', ['table' => $exportable[$tab], 'jours' => $days, 'public' => $audience]) }}" class="btn-outline"><x-icon name="download" class="h-4 w-4" /> Exporter en CSV</a>
                @endisset
                <a href="{{ route('staff.guide', 'super-admin') }}#9-les-statistiques-comprendre-l-audience-et-les-comportements" class="btn-outline"><x-icon name="book" class="h-4 w-4" /> Comment lire ces chiffres</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">

        @unless ($enabled)
            <div class="rounded-xl border border-accent-300 bg-accent-50 px-5 py-4 text-sm">
                <strong>La mesure est désactivée.</strong> Plus rien n'est enregistré. Les chiffres ci-dessous sont ceux d'avant l'arrêt.
                <a href="{{ route('admin.settings.edit') }}#statistiques" class="font-semibold underline">Réactiver dans les paramètres</a>.
            </div>
        @endunless

        {{-- Onglets --}}
        <nav class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1" aria-label="Rubriques des statistiques">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('admin.statistics.index', $query(['onglet' => $key])) }}" @class(['whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition', $pill($tab === $key)]) @if ($tab === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        {{-- Période et public --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Période">
                @foreach ($periods as $d => $label)
                    <a href="{{ route('admin.statistics.index', $query(['jours' => $d])) }}" @class(['rounded-full px-3.5 py-1.5 text-xs font-semibold transition', $pill($days === $d)])>{{ $label }}</a>
                @endforeach
            </div>
            @unless (in_array($tab, ['clients', 'chat']))
                <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Public mesuré">
                    @foreach ($audiences as $key => $label)
                        <a href="{{ route('admin.statistics.index', $query(['public' => $key])) }}" @class(['rounded-full px-3.5 py-1.5 text-xs font-semibold transition', $pill($audience === $key)])>{{ $label }}</a>
                    @endforeach
                </div>
            @endunless
        </div>

        <p class="text-xs text-slate-500">
            Du {{ $stats->from->locale('fr')->isoFormat('D MMMM YYYY') }} au {{ $stats->to->locale('fr')->isoFormat('D MMMM YYYY') }}, heures de la plateforme ({{ $timezone }}).
            Les visites de l'équipe ne sont jamais comptées. Données gardées {{ $retention }} jours.
        </p>

        @include('admin.statistics.tabs.'.$tab, $data)
    </div>
</x-app-layout>
