<x-app-layout title="Demandes | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Demandes" subtitle="Les commandes à confirmer, rendez-vous, devis et clients qui demandent une personne, à traiter en priorité.">
            <x-slot name="actions">
                <a href="{{ route('alerts.edit') }}" class="btn-outline"><x-icon name="bolt" class="h-4 w-4" /> Choisir mes alertes</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $tabs = ['open' => 'À traiter', 'done' => 'Terminées', 'all' => 'Toutes'];
        $tones = ['order' => 'amber', 'appointment' => 'blue', 'quote' => 'indigo', 'human' => 'red'];
        $states = ['new' => ['Nouvelle', 'red'], 'taken' => ['Prise en charge', 'indigo'], 'done' => ['Terminée', 'green'], 'dismissed' => ['Ignorée', 'gray']];
    @endphp

    <div class="mx-auto max-w-5xl space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        @unless ($alertsChosen)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl rounded-bl-md border border-accent-300 bg-accent-50 px-5 py-4 text-sm">
                <p><strong>Choisissez comment être prévenu.</strong> Pour l'instant, vous recevez un e-mail à chaque demande. Vous pouvez aussi être alerté sur WhatsApp et recevoir des rappels.</p>
                <a href="{{ route('alerts.edit') }}" class="btn-accent">Choisir mes alertes</a>
            </div>
        @endunless

        <div class="flex flex-wrap gap-2">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('leads.index', ['etat' => $key]) }}"
                   class="rounded-full px-4 py-1.5 text-sm font-medium transition {{ $tab === $key ? 'bg-brand-600 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:border-brand-300' }}">
                    {{ $label }}@if ($key !== 'all') <span class="opacity-70">({{ $counts[$key] }})</span>@endif
                </a>
            @endforeach
        </div>

        <div class="space-y-3">
            @forelse ($leads as $lead)
                @php [$stateLabel, $stateTone] = $states[$lead->status] ?? [$lead->status, 'gray']; @endphp
                <article @class(['surface rounded-bl-md p-5', 'ring-2 ring-hibiscus-500/30' => $lead->status === 'new'])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-badge :tone="$tones[$lead->kind] ?? 'gray'">{{ $lead->label() }}</x-badge>
                                <x-badge :tone="$stateTone">{{ $stateLabel }}</x-badge>
                                @if ($lead->conversation?->channel === 'whatsapp')
                                    <x-badge tone="green">WhatsApp</x-badge>
                                @endif
                            </div>
                            <h2 class="mt-2 font-display text-base font-bold text-brand-950">{{ $lead->title }}</h2>
                            @if ($lead->summary && $lead->summary !== $lead->title)
                                <p class="mt-1 text-sm text-slate-600">{{ $lead->summary }}</p>
                            @endif
                            <p class="mt-2 text-sm text-slate-500">
                                {{ trim(($lead->contact_name ?: 'Client').' '.($lead->contact_phone ? ': '.$lead->contact_phone : '')) }}
                                · {{ $lead->bot?->name }} · {{ $lead->created_at->diffForHumans() }}
                                @if ($lead->assignee) · pris en charge par {{ $lead->assignee->name }} @endif
                                @if ($lead->reminders > 0 && $lead->status === 'new') · {{ $lead->reminders }} rappel{{ $lead->reminders > 1 ? 's' : '' }} envoyé{{ $lead->reminders > 1 ? 's' : '' }} @endif
                            </p>
                            @if ($lead->conversation?->channel === 'whatsapp' && $lead->isOpen())
                                <p class="mt-2 text-xs text-slate-500">Étiquette conseillée dans WhatsApp Business : <strong class="text-brand-900">{{ \App\Models\Lead::WHATSAPP_LABELS[$lead->kind] }}</strong> (à poser à la main : WhatsApp ne permet pas de le faire automatiquement).</p>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @php
                                $confirmation = $lead->isOpen() && $lead->conversation?->channel === 'whatsapp' ? (\App\Models\Lead::CONFIRMATIONS[$lead->kind] ?? null) : null;
                                $confirmation = $confirmation && isset($confirmable[$lead->bot_id.':'.$confirmation[0]]) ? $confirmation : null;
                            @endphp
                            @if ($confirmation)
                                <a href="{{ route('conversations.show', [$lead->bot_id, $lead->conversation_id, 'modele' => $confirmation[0]]) }}" class="btn-accent">{{ $confirmation[1] }}</a>
                            @endif
                            @if ($lead->conversation)
                                <a href="{{ route('conversations.show', [$lead->bot_id, $lead->conversation_id]) }}" class="{{ $confirmation ? 'btn-outline' : 'btn-primary' }}">Répondre</a>
                            @endif
                            @foreach ($lead->isOpen()
                                ? array_filter([$lead->status === 'new' ? ['take', 'Prendre en charge'] : null, ['done', 'Terminer'], ['dismiss', 'Ignorer']])
                                : [['reopen', 'Rouvrir']] as [$action, $label])
                                <form method="POST" action="{{ route('leads.update', $lead) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="action" value="{{ $action }}">
                                    <button class="btn-outline">{{ $label }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                </article>
            @empty
                <div class="surface flex flex-col items-center gap-3 px-6 py-14 text-center">
                    <x-illus name="inbox" class="h-16 w-16" />
                    <p class="font-semibold text-brand-950">{{ $tab === 'done' ? 'Rien de terminé pour l\'instant.' : 'Rien à traiter.' }}</p>
                    <p class="max-w-md text-sm text-slate-600">Quand un client confirme une commande, prend rendez-vous, demande un devis ou veut parler à une personne, la demande apparaît ici et vous êtes prévenu comme vous l'avez choisi.</p>
                </div>
            @endforelse
        </div>

        <div>{{ $leads->links() }}</div>
    </div>
</x-app-layout>
