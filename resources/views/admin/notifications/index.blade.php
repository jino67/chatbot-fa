@php
    $tone = ['draft' => 'gray', 'scheduled' => 'blue', 'sending' => 'amber', 'sent' => 'green', 'canceled' => 'red'];
    $percent = $reach['users'] > 0 ? round(100 * $reach['with_push'] / $reach['users']) : 0;
@endphp
<x-app-layout title="Notifications aux clients | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Notifications aux clients" subtitle="Envoyez une promotion, une nouveauté ou un message important directement sur le téléphone de vos clients, avec le compteur sur l'icône de l'application.">
            <x-slot name="actions">
                <a href="{{ route('admin.notifications.create') }}" class="btn-primary"><x-icon name="megaphone" class="h-4 w-4" /> Nouvelle notification</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="surface p-5"><p class="text-sm text-slate-600">Clients actifs</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $reach['users'] }}</p></div>
            <div class="surface p-5"><p class="text-sm text-slate-600">Joignables sur téléphone</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $reach['with_push'] }}</p><p class="mt-1 text-xs text-slate-500">{{ $percent }} % des clients</p></div>
            <div class="surface p-5"><p class="text-sm text-slate-600">Appareils activés</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $reach['devices'] }}</p></div>
            <div class="surface p-5"><p class="text-sm text-slate-600">Campagnes</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $campaigns->count() }}</p></div>
        </div>

        @if ($reach['users'] > 0 && $percent < 40)
            <div class="rounded-xl border border-accent-300 bg-accent-50 px-5 py-4 text-sm">
                <strong>Peu de clients ont activé les notifications.</strong> Pour élargir la portée : encouragez l'installation de l'application (l'invitation s'affiche déjà sur leur tableau de bord) et rappelez-leur dans vos messages d'accueil d'appuyer sur « Activer les notifications ». Les autres reçoivent quand même votre message dans la cloche de l'application.
            </div>
        @endif

        <section class="surface">
            <div class="border-b border-slate-100 px-6 py-4"><h2 class="font-display text-lg font-bold text-brand-950">Historique</h2></div>
            @forelse ($campaigns as $campaign)
                <a href="{{ route('admin.notifications.show', $campaign) }}" class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 transition hover:bg-slate-50 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-brand-950">{{ $campaign->title }}</p>
                        <p class="mt-0.5 truncate text-sm text-slate-600">{{ $campaign->body }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ \App\Notify\CampaignAudience::describe($campaign->audience) }}, par {{ $campaign->author?->name ?? 'l\'équipe' }}, {{ ($campaign->finished_at ?? $campaign->scheduled_at ?? $campaign->created_at)->locale('fr')->isoFormat('D MMM, HH:mm') }}</p>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        @if ($campaign->kind === 'important') <x-badge tone="red">Important</x-badge> @else <x-badge tone="brand">Promotion</x-badge> @endif
                        <x-badge :tone="$tone[$campaign->status] ?? 'gray'">{{ $campaign->statusLabel() }}</x-badge>
                        @if (in_array($campaign->status, ['sent', 'sending']))
                            <span class="text-slate-600"><strong class="text-brand-950">{{ $campaign->notified }}</strong> reçu(s), <strong class="text-brand-950">{{ $campaign->pushed }}</strong> sur téléphone</span>
                        @endif
                    </div>
                </a>
            @empty
                <div class="px-6 py-14 text-center">
                    <x-icon name="megaphone" class="mx-auto h-9 w-9 text-slate-300" />
                    <p class="mt-3 font-display text-lg font-bold text-brand-950">Aucune notification envoyée</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-600">Annoncez une promotion, une nouvelle fonction ou une maintenance : vos clients la voient sur leur téléphone.</p>
                    <a href="{{ route('admin.notifications.create') }}" class="btn-primary mt-4">Écrire la première</a>
                </div>
            @endforelse
        </section>
    </div>
</x-app-layout>
