@php
    $tone = ['draft' => 'gray', 'scheduled' => 'blue', 'sending' => 'amber', 'sent' => 'green', 'canceled' => 'red'];
    $progress = $campaign->targeted > 0 ? min(100, round(100 * ($campaign->notified + $campaign->skipped) / $campaign->targeted)) : 0;
@endphp
<x-app-layout title="{{ $campaign->title }} | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header :title="$campaign->title" :subtitle="$audienceText">
            <x-slot name="actions">
                <a href="{{ route('admin.notifications.index') }}" class="btn-outline">Retour</a>
                <a href="{{ route('admin.notifications.create', ['copie' => $campaign->id]) }}" class="btn-outline">Dupliquer</a>
                @if ($campaign->isEditable())
                    <a href="{{ route('admin.notifications.edit', $campaign) }}" class="btn-primary">Modifier</a>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8" @if ($campaign->status === 'sending') x-data x-init="setTimeout(() => location.reload(), 15000)" @endif>

        <section class="surface p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <x-badge :tone="$tone[$campaign->status] ?? 'gray'">{{ $campaign->statusLabel() }}</x-badge>
                    @if ($campaign->kind === 'important') <x-badge tone="red">Message important</x-badge> @else <x-badge tone="brand">Promotion</x-badge> @endif
                    @if ($campaign->also_email) <x-badge>Aussi par e-mail</x-badge> @endif
                </div>
                <p class="text-sm text-slate-500">
                    Par {{ $campaign->author?->name ?? 'l\'équipe' }}
                    @if ($campaign->status === 'scheduled' && $campaign->scheduled_at), partira le {{ $campaign->scheduled_at->locale('fr')->isoFormat('D MMMM à HH:mm') }}
                    @elseif ($campaign->finished_at), terminée le {{ $campaign->finished_at->locale('fr')->isoFormat('D MMMM à HH:mm') }}
                    @endif
                </p>
            </div>
            <p class="mt-4 text-slate-800">{{ $campaign->body }}</p>
            @if ($campaign->url) <p class="mt-2 text-sm text-slate-500">Lien : {{ $campaign->url }}</p> @endif

            @if ($campaign->status === 'sending')
                <div class="mt-5">
                    <div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-500" style="width: {{ $progress }}%"></div></div>
                    <p class="mt-1 text-xs text-slate-500">Envoi en cours : {{ $progress }} %. Cette page se met à jour toute seule, et le reste part chaque minute.</p>
                </div>
            @endif
        </section>

        @if (in_array($campaign->status, ['sending', 'sent']))
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="surface p-5"><p class="text-sm text-slate-600">Personnes visées</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $campaign->targeted }}</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Ont reçu le message</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $campaign->notified }}</p><p class="mt-1 text-xs text-slate-500">dans l'application</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Envoyé sur téléphone</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $campaign->pushed }}</p><p class="mt-1 text-xs text-slate-500">appareil(s){{ $campaign->failed ? ', '.$campaign->failed.' en échec' : '' }}</p></div>
                <div class="surface p-5"><p class="text-sm text-slate-600">Ouverts</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ $readCount }}</p><p class="mt-1 text-xs text-slate-500">{{ \App\Support\StatsFormat::percent($campaign->readRate()) }} de ceux qui l'ont reçu</p></div>
            </div>

            <section class="surface p-6 text-sm text-slate-700">
                <h2 class="font-display text-lg font-bold text-brand-950">Pourquoi tout le monde n'a pas été touché</h2>
                <ul class="mt-3 space-y-1.5">
                    <li><strong>{{ $campaign->skipped }}</strong> personne(s) écartée(s) : elles refusent ce type de message, ou ont déjà reçu une promotion aujourd'hui.</li>
                    <li><strong>{{ $campaign->deferred }}</strong> message(s) retardé(s) jusqu'au matin (heures calmes) : ils partent seuls.</li>
                    @if ($campaign->also_email) <li><strong>{{ $campaign->emailed }}</strong> e-mail(s) envoyé(s).</li> @endif
                    <li>Les clients sans appareil activé ont reçu le message dans la cloche de l'application.</li>
                </ul>
            </section>
        @endif

        @if ($campaign->isEditable())
            <form method="POST" action="{{ route('admin.notifications.cancel', $campaign) }}" onsubmit="return confirm('Annuler cette campagne ?')">@csrf
                <button class="btn-danger">Annuler cette campagne</button>
            </form>
        @endif
    </div>
</x-app-layout>
