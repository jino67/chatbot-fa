<x-app-layout title="Tableau de bord | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header :title="'Bonjour'.(auth()->user()->isClient() ? ', '.\Illuminate\Support\Str::before(auth()->user()->name, ' ') : '')"
                       :subtitle="$workspace->name.' : offre '.$plan?->name">
            <x-slot name="actions">
                <a href="{{ route('bots.create') }}" class="btn-primary"><x-icon name="chat" class="h-4 w-4" /> Nouvel assistant</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">

        @if ($workspace->subscription_status === 'past_due')
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-accent-300 bg-accent-50 px-5 py-4 text-sm">
                <p><strong>Votre abonnement est arrivé à échéance.</strong> Renouvelez-le avant le {{ $workspace->plan_ends_at?->addDays((int) config('platform.billing.grace_days'))->format('d/m/Y') }} pour ne pas repasser à l'offre gratuite.</p>
                <a href="{{ route('billing.show') }}" class="btn-accent">Renouveler</a>
            </div>
        @endif

        @if ($stats['leads'] > 0)
            <a href="{{ route('leads.index') }}" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl rounded-bl-md border border-hibiscus-500/40 bg-white px-5 py-4 text-sm shadow-sm transition hover:shadow-lift">
                <p><strong class="text-hibiscus-600">{{ $stats['leads'] }} demande{{ $stats['leads'] > 1 ? 's' : '' }} à traiter</strong> : commandes à confirmer, rendez-vous ou clients qui attendent une personne.</p>
                <span class="btn-primary">Voir les demandes</span>
            </a>
        @endif
        @unless ($alertsChosen)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl rounded-bl-md border border-accent-300 bg-accent-50 px-5 py-4 text-sm">
                <p><strong>Comment voulez-vous être prévenu ?</strong> Quand un client confirme une commande ou demande une personne, vous recevez un e-mail. Ajoutez WhatsApp et des rappels en deux minutes.</p>
                <a href="{{ route('alerts.edit') }}" class="btn-accent">Choisir mes alertes</a>
            </div>
        @endunless

        <div class="stagger grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['Conversations, 30 jours', $stats['conversations'], null, 'chat'],
                ['Messages reçus, 30 jours', $stats['messages'], null, 'voice'],
                ['Demandes à traiter', $stats['leads'], $stats['leads'] ? 'text-hibiscus-600' : null, 'inbox'],
                ['Questions sans réponse, 30 jours', $stats['unanswered'], $stats['unanswered'] ? 'text-accent-700' : null, 'question'],
            ] as [$label, $value, $tone, $illus])
                <div data-tilt class="surface-link group p-5">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm text-slate-600">{{ $label }}</p>
                        <x-illus :name="$illus" class="h-9 w-9 shrink-0" />
                    </div>
                    <p class="mt-2 font-display text-4xl font-bold {{ $tone ?? 'text-brand-950' }}" data-count="{{ $value }}">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section>
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-display text-lg font-bold">Vos assistants</h2>
                    <a href="{{ route('bots.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-800">Tout voir</a>
                </div>

                @if ($bots->isNotEmpty())
                    <div class="surface divide-y divide-slate-100">
                        @foreach ($bots as $bot)
                            <a href="{{ route('sources.index', $bot) }}" class="group flex items-center justify-between gap-4 px-5 py-4 transition-all duration-300 hover:bg-brand-50/60 hover:pl-6">
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-brand-950">{{ $bot->name }}</p>
                                    <p class="text-sm text-slate-600">{{ $bot->sources_count }} source(s), {{ $bot->conversations_count }} conversation(s)</p>
                                </div>
                                @if ($bot->is_active) <x-badge tone="green">Actif</x-badge> @else <x-badge tone="amber">En pause</x-badge> @endif
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="surface p-10 text-center">
                        <x-illus name="sleep" class="il-live mx-auto h-24 w-24" />
                        <h3 class="mt-2 font-display text-xl font-bold">Créez votre premier assistant</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm text-slate-600">Dix minutes suffisent : choisissez votre métier, ajoutez vos documents, testez, puis publiez.</p>
                        <a href="{{ route('bots.create') }}" class="btn-primary mt-5 px-6 py-3">Créer un assistant</a>
                    </div>
                @endif
            </section>

            <section class="surface h-fit p-5">
                <h2 class="font-display text-lg font-bold">Consommation du mois</h2>
                <div class="mt-4 space-y-5">
                    @foreach ([['Réponses de l\'assistant', 'messages'], ['Assistants', 'bots'], ['Sources de connaissances', 'sources']] as [$label, $key])
                        @php $pct = min(100, (int) round(100 * $usage[$key]['used'] / max(1, $usage[$key]['limit']))); @endphp
                        <div>
                            <div class="flex justify-between text-sm">
                                <span class="text-slate-600">{{ $label }}</span>
                                <span class="font-semibold text-brand-950">{{ $usage[$key]['used'] }} / {{ $usage[$key]['limit'] }}</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-red-500' : 'bg-brand-500' }}" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($plan?->isFree() || max(array_map(fn ($u) => $u['used'] / max(1, $u['limit']), $usage)) >= 0.8)
                    <a href="{{ route('billing.show') }}" class="btn-accent mt-6 w-full">Passer à une offre supérieure</a>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
