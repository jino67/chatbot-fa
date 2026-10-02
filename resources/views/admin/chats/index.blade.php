@use('App\Services\Chats\ChatFilters')
@use('App\Services\Chats\ChatQuery')
@use('App\Support\StatsFormat', 'N')
@php
    $url =fn (array $changes = [], array $extra = []) => route('admin.chats.index', $filters->with($changes)->toQuery() + $extra);
    $pill = fn (bool $on) => $on
        ? 'bg-brand-600 text-white shadow-sm'
        : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-brand-50 hover:text-brand-800';
    $listing = in_array($filters->view, ChatFilters::LISTING, true);
    $short = ['attente' => 'En attente', 'sans_reponse' => 'Sans réponse', 'mecontent' => 'Mécontents', 'non_servi' => 'Non servis', 'erreur' => 'Pannes de l\'IA', 'prospect' => 'Prospects', 'signale' => 'Signalées'];
    $statusTone = ['bot' => 'gray', 'needs_human' => 'amber', 'human' => 'blue', 'closed' => 'gray'];
    $needsLanding = in_array($filters->view, ['kouma', 'prospects'], true) && ! $landing;
@endphp
<x-app-layout title="Conversations | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Conversations" subtitle="Tout ce qui se dit sur la plateforme : les visiteurs de l'assistant de Kouma et les clients de chaque entreprise, tous canaux confondus. Pour repérer ce qui va mal, ce que les gens demandent, et qui attend.">
            <x-slot name="actions">
                @if ($listing)
                    <a href="{{ route('admin.chats.export', $filters->toQuery()) }}" class="btn-outline"><x-icon name="download" class="h-4 w-4" /> Exporter en CSV</a>
                @endif
                <a href="{{ route('staff.guide', 'super-admin') }}#10-les-conversations-voir-tout-ce-qui-se-dit" class="btn-outline"><x-icon name="book" class="h-4 w-4" /> Comment s'en servir</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">

        <div class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-700">
            <x-icon name="scroll" class="mt-0.5 h-4 w-4 shrink-0 text-slate-500" />
            <p>Vous lisez des échanges de personnes qui se sont adressées à l'assistant d'une entreprise. <strong>Chaque conversation ouverte est inscrite au journal</strong> avec votre nom ; les numéros sont partiellement masqués. N'utilisez ces échanges que pour la qualité du service et l'assistance.</p>
        </div>

        {{-- Vues --}}
        <nav class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1" aria-label="Vues des conversations">
            @foreach ($views as $key => $label)
                <a href="{{ $url(['view' => $key, 'flag' => null]) }}" @class(['whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition', $pill($filters->view === $key)]) @if ($filters->view === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        {{-- Période --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Période">
                @foreach (ChatFilters::PERIODS as $d => $label)
                    <a href="{{ $url(['days' => $d]) }}" @class(['rounded-full px-3.5 py-1.5 text-xs font-semibold transition', $pill($filters->days === $d)])>{{ $label }}</a>
                @endforeach
            </div>
            <p class="text-xs text-slate-500">Depuis le {{ $filters->from()->locale('fr')->isoFormat('D MMMM YYYY') }}. Les essais faits dans la zone de test des clients ne sont pas comptés{{ $filters->withTests ? ' (sauf ici)' : '' }}.</p>
        </div>

        @if ($needsLanding)
            <div class="surface px-6 py-12 text-center text-sm text-slate-600">
                L'assistant de la page d'accueil n'est pas encore choisi : on ne sait pas encore quelles conversations sont celles des visiteurs de Kouma.
                <a href="{{ route('admin.settings.edit') }}" class="font-semibold text-brand-700 underline">Le sélectionner dans les paramètres</a>.
            </div>

        @elseif ($listing)
            {{-- En direct --}}
            <section class="surface" x-data="chatsLive(@js($live),@js(route('admin.chats.live')))" x-init="start()">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <div class="flex items-center gap-3">
                        <span class="relative flex h-3 w-3" aria-hidden="true"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span><span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500"></span></span>
                        <h2 class="font-display text-lg font-bold text-brand-950">En ce moment</h2>
                    </div>
                    <p class="text-sm text-slate-600">
                        <strong class="text-brand-950" x-text="live.active"></strong> conversation(s) active(s) dans les 15 dernières minutes<template x-if="live.waiting > 0"><span> · <strong class="text-red-700" x-text="live.waiting + ' client(s) attendent une personne'"></strong></span></template>
                    </p>
                </div>
                <template x-if="live.recent.length">
                    <ul class="divide-y divide-slate-100">
                        <template x-for="m in live.recent" :key="m.id + m.text">
                            <li><a :href="'{{ url('admin/conversations') }}/' + m.id" class="flex items-start justify-between gap-3 px-6 py-2.5 text-sm hover:bg-slate-50">
                                <span class="min-w-0"><span class="font-medium text-slate-900" x-text="m.who"></span> <span class="text-xs text-slate-500" x-text="'→ ' + m.bot + (m.company ? ' (' + m.company + ')' : '')"></span><span class="block truncate text-slate-600" x-text="m.text"></span></span>
                                <span class="shrink-0 text-xs text-slate-400" x-text="m.ago"></span>
                            </a></li>
                        </template>
                    </ul>
                </template>
                <template x-if="! live.recent.length"><p class="px-6 py-5 text-sm text-slate-500">Aucun client n'a écrit dans les 15 dernières minutes.</p></template>
            </section>

            {{-- Chiffres --}}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="surface p-5"><p class="text-sm text-slate-600">Conversations</p><p class="mt-2 font-display text-2xl font-bold text-brand-950 sm:text-3xl">{{ N::number($overview['conversations']) }}</p><p class="mt-1 text-xs text-slate-500">{{ N::number($overview['visitors']) }} personne(s) différente(s), {{ N::number($overview['companies']) }} entreprise(s)</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Questions posées</p><p class="mt-2 font-display text-2xl font-bold text-brand-950 sm:text-3xl">{{ N::number($overview['messages']) }}</p><p class="mt-1 text-xs text-slate-500">{{ N::number($overview['answers']) }} réponses de l'assistant</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Réponses trouvées</p><p class="mt-2 font-display text-2xl font-bold text-brand-950 sm:text-3xl">{{ $overview['answers'] ? N::percent($overview['grounded_rate']) : '–' }}</p><p class="mt-1 text-xs text-slate-500">dans les connaissances ; {{ N::number($overview['ungrounded']) }} sans information</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Avis positifs</p><p class="mt-2 font-display text-2xl font-bold text-brand-950 sm:text-3xl">{{ N::number($overview['positive']) }}</p><p class="mt-1 text-xs {{ $overview['negative'] ? 'text-red-700' : 'text-slate-500' }}">{{ N::number($overview['negative']) }} avis négatif(s)</p></div>
            </div>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="surface p-4"><p class="text-sm text-slate-600">Passées à une personne</p><p class="mt-1 font-display text-2xl font-bold text-brand-950">{{ N::number($overview['handoffs']) }}</p></div>
                <div class="surface p-4 {{ $overview['waiting'] ? 'ring-1 ring-red-300' : '' }}"><p class="text-sm text-slate-600">Clients qui attendent</p><p class="mt-1 font-display text-2xl font-bold {{ $overview['waiting'] ? 'text-red-700' : 'text-brand-950' }}">{{ N::number($overview['waiting']) }}</p><p class="text-xs text-slate-500">depuis plus de {{ ChatQuery::WAIT_MINUTES }} min</p></div>
                <div class="surface p-4 {{ $overview['unserved'] ? 'ring-1 ring-red-300' : '' }}"><p class="text-sm text-slate-600">Clients non servis</p><p class="mt-1 font-display text-2xl font-bold {{ $overview['unserved'] ? 'text-red-700' : 'text-brand-950' }}">{{ N::number($overview['unserved']) }}</p><p class="text-xs text-slate-500">assistant en pause, essai fini, volume atteint</p></div>
                <div class="surface p-4"><p class="text-sm text-slate-600">Demandes enregistrées</p><p class="mt-1 font-display text-2xl font-bold text-brand-950">{{ N::number($overview['leads']) }}</p><p class="text-xs text-slate-500">commandes, rendez-vous, devis</p></div>
            </div>

            <x-stats.card title="Conversations par jour">
                <x-slot name="actions">
                    @if ($rhythm) <a href="{{ $url() }}" class="text-sm font-medium text-brand-700 underline">Masquer les heures d'affluence</a>
                    @else <a href="{{ $url([], ['rythme' => 1]) }}" class="text-sm font-medium text-brand-700 underline">Voir les heures d'affluence</a> @endif
                </x-slot>
                <div class="px-6 py-5"><x-stats.daily :series="$daily" key="sessions" label="conversations" /></div>
                @if ($rhythm)
                    <div class="border-t border-slate-100">
                        <p class="px-6 pt-4 text-sm text-slate-600">
                            @if ($rhythm['peak']) Le plus chargé : {{ mb_strtolower($rhythm['peak']['day']) }} vers {{ $rhythm['peak']['hour'] }} h ({{ $rhythm['peak']['count'] }} questions sur ce créneau). @else Pas encore de question sur cette période. @endif
                            @if (count($rhythm['channels'])) Canaux : {{ collect($rhythm['channels'])->map(fn ($n, $c) => $c.' '.N::number($n))->implode(', ') }}. @endif
                        </p>
                        <x-stats.heatmap :heat="$rhythm" unit="questions" />
                    </div>
                @endif
            </x-stats.card>

            {{-- Signaux --}}
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Signaux">
                <span class="text-sm text-slate-600">Repérer :</span>
                @foreach ($flagCounts as $flag => $count)
                    <a href="{{ $url(['flag' => $filters->flag === $flag ? null : $flag]) }}" title="{{ ChatFilters::FLAGS[$flag] }}"
                       @class(['inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition', $pill($filters->flag === $flag)])>
                        {{ $short[$flag] }}
                        <span @class(['rounded-full px-1.5 tabular-nums', 'bg-white/25' => $filters->flag === $flag, 'bg-slate-100 text-slate-700' => $filters->flag !== $flag && ! ($count > 0 && in_array($flag, ['attente', 'non_servi', 'erreur'])), 'bg-red-100 text-red-700' => $filters->flag !== $flag && $count > 0 && in_array($flag, ['attente', 'non_servi', 'erreur'])])>{{ N::number($count) }}</span>
                    </a>
                @endforeach
            </div>

            {{-- Filtres fins --}}
            <form method="GET" action="{{ route('admin.chats.index') }}" class="surface grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
                <input type="hidden" name="vue" value="{{ $filters->view }}"><input type="hidden" name="jours" value="{{ $filters->days }}">
                @if ($filters->flag) <input type="hidden" name="signal" value="{{ $filters->flag }}"> @endif
                <div class="lg:col-span-2">
                    <label for="q" class="text-xs font-medium text-slate-600">Rechercher (nom, numéro, e-mail ou mot dans l'échange)</label>
                    <input id="q" name="q" value="{{ $filters->q }}" maxlength="80" class="field" placeholder="ex. remboursement">
                </div>
                @unless (in_array($filters->view, ['kouma', 'prospects']))
                    <div>
                        <label for="client" class="text-xs font-medium text-slate-600">Entreprise</label>
                        <select id="client" name="client" class="field"><option value="">Toutes</option>@foreach ($workspaces as $w)<option value="{{ $w->id }}" @selected($filters->workspace === $w->id)>{{ $w->name }}</option>@endforeach</select>
                    </div>
                    <div>
                        <label for="assistant" class="text-xs font-medium text-slate-600">Assistant</label>
                        <select id="assistant" name="assistant" class="field"><option value="">Tous</option>@foreach ($bots as $b)<option value="{{ $b->id }}" @selected($filters->bot === $b->id)>{{ $b->name }}</option>@endforeach</select>
                    </div>
                @endunless
                <div>
                    <label for="canal" class="text-xs font-medium text-slate-600">Canal</label>
                    <select id="canal" name="canal" class="field"><option value="">Tous</option>@foreach (ChatFilters::CHANNELS as $k => $label)<option value="{{ $k }}" @selected($filters->channel === $k)>{{ $label }}</option>@endforeach</select>
                </div>
                <div>
                    <label for="statut" class="text-xs font-medium text-slate-600">État</label>
                    <select id="statut" name="statut" class="field"><option value="">Tous</option>@foreach (ChatFilters::STATUSES as $k => $label)<option value="{{ $k }}" @selected($filters->status === $k)>{{ $label }}</option>@endforeach</select>
                </div>
                <div>
                    <label for="tri" class="text-xs font-medium text-slate-600">Trier par</label>
                    <select id="tri" name="tri" class="field">@foreach (ChatFilters::SORTS as $k => $label)<option value="{{ $k }}" @selected($filters->sort === $k)>{{ $label }}</option>@endforeach</select>
                </div>
                <div class="flex items-end gap-3 lg:col-span-2">
                    <label class="flex items-center gap-2 pb-2 text-sm text-slate-700"><input type="checkbox" name="tests" value="1" @checked($filters->withTests) class="rounded border-slate-300"> Avec les essais de la zone de test</label>
                </div>
                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-6">
                    <button class="btn-primary">Filtrer</button>
                    @if ($filters->isFiltered()) <a href="{{ route('admin.chats.index', ['vue' => $filters->view, 'jours' => $filters->days]) }}" class="btn-outline">Tout effacer</a> @endif
                    <span class="ms-auto text-sm text-slate-600">{{ N::number($conversations->total()) }} conversation(s)</span>
                </div>
            </form>

            {{-- Liste --}}
            <div class="surface divide-y divide-slate-100">
                @forelse ($conversations as $c)
                    <a href="{{ route('admin.chats.show', $c->id) }}" class="block px-5 py-4 transition hover:bg-slate-50">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="font-medium text-slate-900">{{ ChatQuery::who($c) }}</span>
                                    <span class="text-xs text-slate-500">{{ $c->bot?->name ?? 'Assistant supprimé' }}@if ($c->bot_id !== $landingId && $c->workspace) chez {{ $c->workspace->name }}@elseif ($c->bot_id === $landingId) (visiteurs de Kouma)@endif</span>
                                    <x-badge>{{ ChatFilters::CHANNELS[$c->channel] ?? $c->channel }}</x-badge>
                                    @php $badges = ChatQuery::badges($c, $landingId); @endphp
                                    {{-- « Attend depuis 3 heures » dit déjà que le client attend une personne : pas besoin d'un second repère. --}}
                                    @if ($c->status !== 'bot' && ! collect($badges)->contains('key', 'attente')) <x-badge :tone="$statusTone[$c->status] ?? 'gray'">{{ ChatFilters::STATUSES[$c->status] ?? $c->status }}</x-badge> @endif
                                    @foreach ($badges as $badge)
                                        <x-badge :tone="$badge['tone']">{{ $badge['label'] }}</x-badge>
                                    @endforeach
                                </div>
                                @if ($c->first_text) <p class="mt-1.5 truncate text-sm text-slate-700"><span class="text-slate-500">Question :</span> {{ \Illuminate\Support\Str::limit($c->first_text, 130) }}</p> @endif
                                @if ($c->last_text && $c->last_text !== $c->first_text) <p class="truncate text-sm text-slate-500"><span>Dernier message :</span> {{ \Illuminate\Support\Str::limit($c->last_text, 130) }}</p> @endif
                            </div>
                            <div class="shrink-0 text-right text-xs text-slate-500">
                                <div>{{ $c->last_message_at?->locale('fr')->diffForHumans() }}</div>
                                <div class="mt-1 tabular-nums">{{ $c->messages_count }} message(s)</div>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="px-6 py-14 text-center text-sm text-slate-500">
                        Aucune conversation ne correspond à ces choix.
                        @if ($filters->isFiltered()) <a href="{{ route('admin.chats.index', ['vue' => $filters->view, 'jours' => $filters->days]) }}" class="font-semibold text-brand-700 underline">Tout effacer</a> @else Essayez une période plus longue. @endif
                    </div>
                @endforelse
            </div>
            <div>{{ $conversations->links() }}</div>

        @elseif ($filters->view === 'questions')
            <x-stats.card title="Ce que les gens demandent et que les assistants ne savent pas" hint="Les mêmes questions sont regroupées, toutes entreprises confondues. C'est le meilleur guide pour savoir quoi ajouter aux connaissances de l'assistant de Kouma, et quoi conseiller à vos clients.">
                @if (! count($questions))
                    <p class="px-6 py-10 text-center text-sm text-slate-500">Aucune question sans réponse sur cette période.</p>
                @else
                    <div class="overflow-x-auto"><table class="min-w-full text-sm">
                        <thead class="text-left text-slate-500"><tr><th class="px-6 py-3 font-medium">Question</th><th class="px-3 py-3 text-right font-medium">Fois</th><th class="px-3 py-3 text-right font-medium">Entreprises</th><th class="px-3 py-3 font-medium">Assistants</th><th class="px-6 py-3 font-medium">Dernière</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($questions as $row)
                                <tr>
                                    <td class="px-6 py-3"><a href="{{ route('admin.chats.show', $row['conversation']) }}" class="break-words font-medium text-slate-900 hover:text-brand-700">{{ $row['question'] }}</a></td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ N::number($row['count']) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ N::number($row['companies']) }}</td>
                                    <td class="px-3 py-3 text-xs text-slate-600">{{ implode(', ', $row['bots']) }}</td>
                                    <td class="whitespace-nowrap px-6 py-3 text-xs text-slate-500">{{ $row['last_at']->locale('fr')->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-stats.card>

        @else
            <x-stats.card title="Les conversations, entreprise par entreprise" hint="Qui utilise vraiment son assistant, et où il manque d'informations. Cliquez sur une entreprise pour lire ses conversations.">
                @if (! count($companies))
                    <p class="px-6 py-10 text-center text-sm text-slate-500">Aucune conversation sur cette période.</p>
                @else
                    <div class="overflow-x-auto"><table class="min-w-full text-sm">
                        <thead class="text-left text-slate-500"><tr><th class="px-6 py-3 font-medium">Entreprise</th><th class="px-3 py-3 text-right font-medium">Conversations</th><th class="px-3 py-3 text-right font-medium">Questions</th><th class="px-3 py-3 text-right font-medium">Sans réponse</th><th class="px-3 py-3 text-right font-medium">Passées à une personne</th><th class="px-3 py-3 text-right font-medium">Avis négatifs</th><th class="px-6 py-3 font-medium">Dernière</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($companies as $row)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-6 py-3"><a href="{{ route('admin.chats.index', ['vue' => 'clients', 'client' => $row['id'], 'jours' => $filters->days]) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $row['name'] }}</a> @if ($row['plan']) <span class="ms-1 text-xs text-slate-500">{{ $row['plan'] }}</span> @endif</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ N::number($row['conversations']) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ N::number($row['messages']) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ N::number($row['ungrounded']) }} <span @class(['text-xs', 'text-red-700' => $row['ungrounded_rate'] >= 30, 'text-slate-500' => $row['ungrounded_rate'] < 30])>({{ N::percent($row['ungrounded_rate']) }})</span></td>
                                    <td class="px-3 py-3 text-right tabular-nums">{{ N::number($row['handoffs']) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums {{ $row['negative'] ? 'text-red-700' : '' }}">{{ N::number($row['negative']) }}</td>
                                    <td class="whitespace-nowrap px-6 py-3 text-xs text-slate-500">{{ $row['last_at']?->locale('fr')->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-stats.card>
        @endif
    </div>

    @once
        <script>
            function chatsLive(initial, url) {
                return {
                    live: initial,
                    start() {
                        setInterval(() => {
                            if (document.hidden) return;
                            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                                .then((r) => r.ok ? r.json() : null).then((data) => { if (data) this.live = data; }).catch(() => {});
                        }, 20000);
                    },
                };
            }
        </script>
    @endonce
</x-app-layout>
