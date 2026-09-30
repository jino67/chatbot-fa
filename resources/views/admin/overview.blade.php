<x-app-layout title="Vue d'ensemble | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Vue d'ensemble" :subtitle="'Pilotage de '.$brand['name'].' : clients, abonnements, IA et demandes à traiter.'">
            <x-slot name="actions">
                <a href="{{ route('admin.workspaces.create') }}" class="btn-primary"><x-icon name="building" class="h-4 w-4" /> Nouvel espace client</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">

        {{-- Etat de l'IA : la premiere chose a voir --}}
        @if ($aiOffline)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-accent-300 bg-accent-50 px-5 py-4 text-sm">
                <p><strong>Aucune clé d'IA n'est configurée.</strong> L'assistant répond en mode hors ligne (extraits de vos sources, sans rédaction). Ajoutez une clé Claude, OpenAI ou Llama.</p>
                @if (auth()->user()->isSuperAdmin()) <a href="{{ route('admin.ai.index') }}" class="btn-accent">Configurer l'IA</a> @endif
            </div>
        @elseif ($aiProblems->isNotEmpty())
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900">
                <p>
                    <strong>{{ $aiProblems->count() }} fournisseur(s) d'IA en difficulté :</strong>
                    {{ $aiProblems->map(fn ($p) => $p->name.' ('.$p->statusLabel().')')->implode(', ') }}.
                    Fournisseur actif : <strong>{{ $aiActive?->name ?? 'aucun' }}</strong>.
                </p>
                @if (auth()->user()->isSuperAdmin()) <a href="{{ route('admin.ai.index') }}" class="btn-danger">Voir la chaîne</a> @endif
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['Espaces clients', $stats['workspaces'], $stats['paying'].' payant(s)'],
                ['Assistants', $stats['bots'], $stats['channels'].' WhatsApp actif(s)'],
                ['Conversations, 7 jours', $stats['conversations'], null],
                ['Réponses IA ce mois', $stats['messages'], null],
            ] as [$label, $value, $hint])
                <div class="surface p-5">
                    <p class="text-sm text-slate-600">{{ $label }}</p>
                    <p class="mt-2 font-display text-4xl font-bold text-brand-950">{{ number_format($value, 0, ',', ' ') }}</p>
                    @if ($hint) <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p> @endif
                </div>
            @endforeach
        </div>

        <div class="surface flex flex-wrap items-center justify-between gap-4 p-5">
            <div>
                <p class="text-sm text-slate-600">Encaissé ce mois-ci (FCFA)</p>
                <p class="font-display text-3xl font-bold text-brand-950">{{ number_format($stats['revenue'], 0, ',', "\u{202F}") }}</p>
            </div>
            <p class="max-w-md text-sm text-slate-600">Somme des paiements enregistrés depuis le début du mois. Le détail se trouve dans la fiche de chaque espace.</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Demandes d'offre --}}
            <section class="surface">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="font-display text-lg font-bold">Demandes d'offre à traiter</h2>
                    <a href="{{ route('admin.plan-requests.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-800">Tout voir</a>
                </div>
                @forelse ($pendingPlans as $req)
                    <a href="{{ route('admin.workspaces.show', $req->workspace_id) }}" class="flex items-center justify-between gap-3 px-6 py-4 hover:bg-slate-50 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                        <div>
                            <p class="font-medium text-brand-950">{{ $req->workspace?->name }}</p>
                            <p class="text-sm text-slate-600">souhaite l'offre {{ $req->plan }}, {{ $req->created_at->diffForHumans() }}</p>
                        </div>
                        <x-badge tone="amber">À encaisser</x-badge>
                    </a>
                @empty
                    <p class="px-6 py-10 text-center text-sm text-slate-600">Aucune demande en attente.</p>
                @endforelse
            </section>

            {{-- Demandes WhatsApp --}}
            <section class="surface">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h2 class="font-display text-lg font-bold">Activations WhatsApp à traiter</h2>
                    <a href="{{ route('admin.requests.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-800">Tout voir</a>
                </div>
                @forelse ($pendingChannels as $req)
                    <a href="{{ route('admin.requests.show', $req->id) }}" class="flex items-center justify-between gap-3 px-6 py-4 hover:bg-slate-50 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                        <div>
                            <p class="font-medium text-brand-950">{{ $req->business_name }} <span class="font-normal text-slate-500">{{ $req->phone_number }}</span></p>
                            <p class="text-sm text-slate-600">{{ $req->workspace?->name }}, assistant « {{ $req->bot?->name }} »</p>
                        </div>
                        <x-badge tone="blue">{{ $req->statusLabel() }}</x-badge>
                    </a>
                @empty
                    <p class="px-6 py-10 text-center text-sm text-slate-600">Aucune demande en attente.</p>
                @endforelse
            </section>
        </div>

        {{-- Echeances --}}
        <section class="surface">
            <div class="border-b border-slate-100 px-6 py-4"><h2 class="font-display text-lg font-bold">Abonnements qui arrivent à échéance</h2></div>
            @forelse ($expiring as $ws)
                <a href="{{ route('admin.workspaces.show', $ws) }}" class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 hover:bg-slate-50 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                    <div>
                        <p class="font-medium text-brand-950">{{ $ws->name }}</p>
                        <p class="text-sm text-slate-600">Offre {{ $ws->planModel()?->name }}, fin le {{ $ws->plan_ends_at->format('d/m/Y') }}</p>
                    </div>
                    @if ($ws->plan_ends_at->isPast()) <x-badge tone="red">Échu depuis {{ $ws->plan_ends_at->diffForHumans(null, true) }}</x-badge>
                    @else <x-badge tone="amber">Dans {{ $ws->plan_ends_at->diffForHumans(null, true) }}</x-badge> @endif
                </a>
            @empty
                <p class="px-6 py-10 text-center text-sm text-slate-600">Aucune échéance dans les {{ config('platform.billing.reminder_days') }} prochains jours.</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
