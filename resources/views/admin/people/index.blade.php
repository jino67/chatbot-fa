@use('App\Services\Users\UserFilters')
@use('App\Support\StatsFormat', 'N')
@php
    $url = fn (array $changes = []) => route('admin.people.index', $filters->with($changes)->toQuery());
    $pill = fn (bool $on) => $on
        ? 'bg-brand-600 text-white shadow-sm'
        : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-brand-50 hover:text-brand-800';
    $tone = ['amber' => 'amber', 'blue' => 'blue', 'green' => 'green', 'red' => 'red', 'gray' => 'gray'];
    $initialTone = ['amber' => 'bg-accent-100 text-accent-800', 'blue' => 'bg-sky-100 text-sky-800', 'green' => 'bg-emerald-100 text-emerald-800', 'red' => 'bg-red-100 text-red-800', 'gray' => 'bg-slate-100 text-slate-700'];
    [$segmentLabel, $segmentHint] = UserFilters::SEGMENTS[$filters->segment];
@endphp
<x-app-layout title="Utilisateurs | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Utilisateurs" subtitle="Toutes les personnes inscrites : d'où elles viennent, où elles en sont, et qui contacter aujourd'hui. Plus poussé que « Espaces clients », qui gère l'entreprise : ici, on suit la personne.">
            <x-slot name="actions">
                @if ($filters->segment !== 'tous' || $filters->isFiltered())
                    <a href="{{ route('admin.people.bulk', $filters->toQuery()) }}" class="btn-primary"><x-icon name="inbox" class="h-4 w-4" /> Écrire à cette sélection</a>
                @endif
                <a href="{{ route('admin.people.export', $filters->toQuery()) }}" class="btn-outline"><x-icon name="download" class="h-4 w-4" /> Exporter en CSV</a>
                <a href="{{ route('staff.guide', 'super-admin') }}#11-les-utilisateurs-suivre-et-contacter-les-inscrits" class="btn-outline"><x-icon name="book" class="h-4 w-4" /> Comment s'en servir</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">

        <div class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-700">
            <x-icon name="scroll" class="mt-0.5 h-4 w-4 shrink-0 text-slate-500" />
            <p>Ces pages montrent des données personnelles (nom, e-mail, téléphone). <strong>Chaque fiche ouverte, chaque contact et chaque export est inscrit au journal</strong> avec votre nom. Contactez les personnes pour les aider, jamais pour les harceler : à leur demande, marquez-les « Ne plus contacter ».</p>
        </div>

        {{-- Chiffres --}}
        <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="surface p-5"><p class="text-sm text-slate-600">Inscrits</p><p class="mt-2 font-display text-2xl font-bold text-brand-950 sm:text-3xl">{{ N::number($overview['total']) }}</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Cette semaine</p><p class="mt-2 font-display text-2xl font-bold text-brand-950 sm:text-3xl">{{ N::number($overview['week']) }}</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Actifs ce mois</p><p class="mt-2 font-display text-2xl font-bold text-brand-950 sm:text-3xl">{{ N::number($overview['month_active']) }}</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Payants</p><p class="mt-2 font-display text-2xl font-bold text-brand-950 sm:text-3xl">{{ N::number($overview['paying']) }}</p></div>
            </div>
            <div class="surface p-4">
                <p class="text-sm font-medium text-slate-700">Inscription par</p>
                <ul class="mt-2 space-y-1 text-sm">
                    @forelse ($bySource as $src => $n)
                        <li class="flex justify-between gap-3"><span class="text-slate-600">{{ UserFilters::SOURCES[$src] ?? ucfirst($src) }}</span><span class="tabular-nums font-medium text-brand-950">{{ N::number($n) }}</span></li>
                    @empty
                        <li class="text-slate-500">Personne pour l'instant.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Segments --}}
        <nav class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1" aria-label="Segments">
            @foreach (UserFilters::SEGMENTS as $key => [$label])
                @php $n = $counts[$key] ?? 0; $alert = in_array($key, ['a_relancer', 'essai_fin', 'essai_expire', 'profil']) && $n > 0; @endphp
                <a href="{{ $url(['segment' => $key]) }}" @class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-2 text-sm font-medium transition', $pill($filters->segment === $key)]) @if ($filters->segment === $key) aria-current="page" @endif>
                    {{ $label }}
                    <span @class(['rounded-full px-1.5 text-xs tabular-nums', 'bg-white/25' => $filters->segment === $key, 'bg-red-100 text-red-700' => $filters->segment !== $key && $alert, 'bg-slate-100 text-slate-700' => $filters->segment !== $key && ! $alert])>{{ N::number($n) }}</span>
                </a>
            @endforeach
        </nav>
        <p class="text-sm text-slate-600"><strong class="text-brand-950">{{ $segmentLabel }}</strong> : {{ $segmentHint }}</p>

        {{-- Filtres --}}
        <form method="GET" action="{{ route('admin.people.index') }}" class="surface grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
            @if ($filters->segment !== 'tous') <input type="hidden" name="segment" value="{{ $filters->segment }}"> @endif
            <div class="lg:col-span-2">
                <label for="q" class="text-xs font-medium text-slate-600">Rechercher (nom, e-mail, téléphone, entreprise)</label>
                <input id="q" name="q" value="{{ $filters->q }}" maxlength="80" class="field" placeholder="ex. Awa">
            </div>
            <div>
                <label for="source" class="text-xs font-medium text-slate-600">Inscription par</label>
                <select id="source" name="source" class="field"><option value="">Toutes</option>@foreach (UserFilters::SOURCES as $k => $label)<option value="{{ $k }}" @selected($filters->source === $k)>{{ $label }}</option>@endforeach</select>
            </div>
            <div>
                <label for="offre" class="text-xs font-medium text-slate-600">Offre</label>
                <select id="offre" name="offre" class="field"><option value="">Toutes</option>@foreach ($plans as $plan)<option value="{{ $plan->slug }}" @selected($filters->plan === $plan->slug)>{{ $plan->name }}</option>@endforeach</select>
            </div>
            <div>
                <label for="statut" class="text-xs font-medium text-slate-600">Suivi</label>
                <select id="statut" name="statut" class="field"><option value="">Tous</option>@foreach (UserFilters::CRM as $k => $label)<option value="{{ $k }}" @selected($filters->crm === $k)>{{ $label }}</option>@endforeach</select>
            </div>
            <div>
                <label for="etat" class="text-xs font-medium text-slate-600">État</label>
                <select id="etat" name="etat" class="field"><option value="">Tous</option>@foreach (UserFilters::STATES as $k => $label)<option value="{{ $k }}" @selected($filters->state === $k)>{{ $label }}</option>@endforeach</select>
            </div>
            <div>
                <label for="responsable" class="text-xs font-medium text-slate-600">Responsable</label>
                <select id="responsable" name="responsable" class="field"><option value="">Tous</option>@foreach ($staff as $member)<option value="{{ $member->id }}" @selected($filters->owner === $member->id)>{{ $member->name }}</option>@endforeach</select>
            </div>
            <div>
                <label for="tri" class="text-xs font-medium text-slate-600">Trier par</label>
                <select id="tri" name="tri" class="field">@foreach (UserFilters::SORTS as $k => $label)<option value="{{ $k }}" @selected($filters->sort === $k)>{{ $label }}</option>@endforeach</select>
            </div>
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-6">
                <button class="btn-primary">Filtrer</button>
                @if ($filters->isFiltered()) <a href="{{ $url(['q' => '', 'source' => null, 'plan' => null, 'state' => null, 'crm' => null, 'owner' => null, 'sort' => 'recent']) }}" class="btn-outline">Tout effacer</a> @endif
                <span class="ms-auto text-sm text-slate-600">{{ N::number($users->total()) }} personne(s)</span>
            </div>
        </form>

        {{-- Liste --}}
        <div class="surface divide-y divide-slate-100">
            @forelse ($users as $u)
                @php
                    $stage = $u->stage;
                    $due = $u->crm_next_follow_up_at && \Illuminate\Support\Carbon::parse($u->crm_next_follow_up_at)->isPast() && ! in_array($u->crm_status, ['stop', 'perdu', 'client']);
                @endphp
                <a href="{{ route('admin.people.show', $u->id) }}" class="block px-5 py-4 transition hover:bg-slate-50">
                    <div class="flex items-start gap-4">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full font-display text-lg font-bold {{ $initialTone[$stage['tone']] ?? $initialTone['gray'] }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-medium text-slate-900">{{ $u->name }}</span>
                                <x-badge :tone="$tone[$stage['tone']] ?? 'gray'">{{ $stage['label'] }}</x-badge>
                                @foreach ($stage['signals'] as $signal) <x-badge :tone="$tone[$signal['tone']] ?? 'gray'">{{ $signal['label'] }}</x-badge> @endforeach
                                @if ($u->crm_status && $u->crm_status !== 'nouveau') <x-badge :tone="$u->crm_status === 'stop' ? 'red' : 'indigo'">{{ UserFilters::CRM[$u->crm_status] ?? $u->crm_status }}</x-badge> @endif
                                @if ($due) <x-badge tone="red">À relancer</x-badge> @endif
                            </div>
                            <p class="mt-0.5 truncate text-sm text-slate-600">{{ $u->email }}@if ($u->phone) <span class="text-slate-400">·</span> {{ $u->phone }}@endif</p>
                            <p class="truncate text-xs text-slate-500">
                                {{ $u->company ?: 'Sans entreprise' }}@if ($u->country), {{ $u->country }}@endif
                                <span class="text-slate-300">|</span> {{ UserFilters::SOURCES[$u->signup_source ?: 'email'] ?? $u->signup_source }}
                                <span class="text-slate-300">|</span> {{ $u->bots_count }} assistant(s), {{ $u->conversations_count }} conversation(s)
                            </p>
                        </div>
                        <div class="hidden shrink-0 text-right text-xs text-slate-500 sm:block">
                            <p class="font-medium text-slate-700">{{ $plans->firstWhere('slug', $u->plan)?->name ?? $u->plan }}</p>
                            <p>Inscrit {{ \Illuminate\Support\Carbon::parse($u->created_at)->locale('fr')->diffForHumans() }}</p>
                            <p>{{ $u->last_activity ? 'Actif '.\Illuminate\Support\Carbon::parse($u->last_activity)->locale('fr')->diffForHumans() : 'Jamais connecté' }}</p>
                        </div>
                    </div>
                </a>
            @empty
                <div class="px-6 py-14 text-center text-sm text-slate-500">
                    Personne ne correspond à ces choix.
                    @if ($filters->isFiltered()) <a href="{{ $url(['q' => '', 'source' => null, 'plan' => null, 'state' => null, 'crm' => null, 'owner' => null, 'sort' => 'recent']) }}" class="font-semibold text-brand-700 underline">Tout effacer</a> @endif
                </div>
            @endforelse
        </div>
        <div>{{ $users->links() }}</div>
    </div>
</x-app-layout>
