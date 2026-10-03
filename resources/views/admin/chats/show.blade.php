@use('App\Services\Chats\ChatFilters')
@use('App\Services\Chats\ChatQuery')
@use('App\Services\Chats\ChatDiagnosis')
@use('App\Support\StatsFormat', 'N')
@php
    $who = ChatQuery::who($conversation);
    $facts = $diagnosis['facts'];
    $outcome = $diagnosis['outcome'];
    $levelStyle = [
        'bad' => 'border-red-200 bg-red-50 text-red-800',
        'warn' => 'border-accent-300 bg-accent-50 text-accent-800',
        'info' => 'border-slate-200 bg-slate-50 text-slate-700',
        'ok' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
    ];
    $levelWord = ['bad' => 'À traiter', 'warn' => 'À surveiller', 'info' => 'À noter', 'ok' => 'Bien'];
    $statusTone = ['bot' => 'gray', 'needs_human' => 'amber', 'human' => 'blue', 'closed' => 'gray'];
    $companyName = $conversation->workspace?->name;
    // Les visiteurs de Kouma lui ont laissé leurs coordonnées à elle : elles servent à les recontacter. Pour les clients d'une entreprise, on masque.
    $showContact = $isLanding;
    $phone = $conversation->contact_phone ?: ($conversation->channel === 'whatsapp' ? '+'.ltrim((string) $conversation->external_id, '+') : null);
@endphp
<x-app-layout title="Conversation n° {{ $conversation->id }} | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Conversation n° {{ $conversation->id }}" subtitle="{{ $who }} avec {{ $bot?->name ?? 'un assistant supprimé' }}{{ $isLanding ? ' (visiteur de Kouma)' : ($companyName ? ', chez '.$companyName : '') }}, {{ $conversation->created_at->locale('fr')->isoFormat('D MMMM YYYY [à] HH:mm') }}.">
            <x-slot name="actions">
                <a href="{{ $back }}" class="btn-outline">Retour à la liste</a>
                <a href="{{ route('admin.chats.transcript', $conversation->id) }}" class="btn-outline"><x-icon name="download" class="h-4 w-4" /> Texte</a>
                @if ($conversation->workspace)
                    <form method="POST" action="{{ route('admin.workspaces.enter', $conversation->workspace) }}">@csrf
                        <button class="btn-primary" title="Ouvrir l'espace de l'entreprise pour répondre ou corriger l'assistant">Entrer dans l'espace</button>
                    </form>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">

        @if (session('status')) <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div> @endif
        @if ($errors->any()) <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div> @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="min-w-0 space-y-6 lg:col-span-2">

                {{-- Diagnostic --}}
                <section class="surface">
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-6 py-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-display text-lg font-bold text-brand-950">{{ $outcome['label'] }}</h2>
                                <x-badge>{{ ChatFilters::CHANNELS[$conversation->channel] ?? $conversation->channel }}</x-badge>
                                @if ($flagged) <x-badge tone="red">Signalée</x-badge> @endif
                                @if ($reviewed) <x-badge>Examinée</x-badge> @endif
                            </div>
                            <p class="mt-1 max-w-xl text-sm text-slate-600">{{ $outcome['hint'] }}</p>
                        </div>
                        @if ($diagnosis['score'] !== null)
                            <div class="text-right">
                                <p class="font-display text-3xl font-bold text-brand-950">{{ $diagnosis['score'] }}<span class="text-base font-medium text-slate-500"> / 100</span></p>
                                <x-badge :tone="$diagnosis['grade']['tone']">{{ $diagnosis['grade']['label'] }}</x-badge>
                            </div>
                        @endif
                    </div>

                    <ul class="space-y-2 px-6 py-4">
                        @foreach ($diagnosis['signals'] as $signal)
                            <li class="flex items-start gap-3 rounded-lg border px-3 py-2 text-sm {{ $levelStyle[$signal['level']] }}">
                                <span class="mt-0.5 shrink-0 text-xs font-semibold">{{ $levelWord[$signal['level']] }}</span><span>{{ $signal['text'] }}</span>
                            </li>
                        @endforeach
                        @foreach ($diagnosis['repeated'] as $text)
                            <li class="px-3 text-xs text-slate-500">Question répétée : « {{ $text }} »</li>
                        @endforeach
                    </ul>

                    <dl class="grid grid-cols-2 gap-4 border-t border-slate-100 px-6 py-4 text-sm sm:grid-cols-4">
                        <div><dt class="text-slate-500">Messages</dt><dd class="font-semibold text-brand-950">{{ $facts['messages'] }} <span class="font-normal text-slate-500">({{ $facts['client'] }} du client)</span></dd></div>
                        <div><dt class="text-slate-500">Durée</dt><dd class="font-semibold text-brand-950">{{ N::duration($facts['duration']) }}</dd></div>
                        <div><dt class="text-slate-500">Première réponse</dt><dd class="font-semibold text-brand-950">{{ $facts['first_response'] !== null ? N::duration($facts['first_response']) : '–' }}</dd></div>
                        <div><dt class="text-slate-500">Temps de calcul moyen</dt><dd class="font-semibold text-brand-950">{{ $facts['latency'] ? N::decimal($facts['latency'] / 1000).' s' : '–' }}</dd></div>
                        <div><dt class="text-slate-500">Réponses fondées</dt><dd class="font-semibold text-brand-950">{{ $facts['grounded'] }} sur {{ $facts['assistant'] }}</dd></div>
                        <div><dt class="text-slate-500">Conseiller</dt><dd class="font-semibold text-brand-950">{{ $facts['agent'] ? $facts['agent'].' message(s)' : 'aucun' }}</dd></div>
                        <div><dt class="text-slate-500">Modèle</dt><dd class="font-semibold text-brand-950">{{ $facts['models'] ? implode(', ', $facts['models']) : '–' }}</dd></div>
                        <div><dt class="text-slate-500">Langue</dt><dd class="font-semibold text-brand-950">{{ $facts['languages'] ? implode(', ', $facts['languages']) : '–' }}{{ $facts['voice'] ? ' (voix)' : '' }}</dd></div>
                    </dl>
                </section>

                {{-- Échange --}}
                <section class="surface">
                    <div class="border-b border-slate-100 px-6 py-4">
                        <h2 class="font-display text-lg font-bold text-brand-950">L'échange</h2>
                        <p class="mt-0.5 text-sm text-slate-600">Sous chaque réponse : ce que l'assistant avait trouvé, le modèle utilisé et le temps de calcul.</p>
                    </div>
                    <div class="space-y-3 px-5 py-5">
                        @forelse ($diagnosis['timeline'] as $row)
                            @php
                                $m = $row['message'];
                                $isUser = $m->role === 'user';
                                $isAgent = $m->role === 'agent';
                                $isSystem = $m->role === 'system';
                                $bubble = $isUser ? 'rounded-bl-sm bg-slate-100 text-slate-800' : ($isAgent ? 'rounded-br-sm bg-emerald-600 text-white' : ($isSystem ? 'bg-slate-50 text-slate-600 italic' : 'rounded-br-sm bg-brand-600 text-white'));
                                $meta = $m->meta ?? [];
                                $reason = $meta['reason'] ?? null;
                                $sources = collect($m->sources ?? [])->pluck('title')->filter()->unique()->take(4);
                            @endphp
                            @if ($row['day'])
                                <p class="py-1 text-center text-xs font-medium text-slate-500">{{ ucfirst($row['day']) }}</p>
                            @endif
                            @if ($row['gap'])
                                <p @class(['text-center text-[11px]', 'font-medium text-red-700' => $row['slow'], 'text-slate-400' => ! $row['slow']])>{{ $row['slow'] ? 'Le client a attendu ' : '' }}{{ $row['gap'] }} sans message</p>
                            @endif
                            <div class="flex {{ $isUser ? '' : 'justify-end' }}" id="m{{ $m->id }}">
                                <div class="max-w-[85%]">
                                    <div class="whitespace-pre-wrap break-words rounded-2xl px-4 py-2 text-sm {{ $bubble }}">{{ $m->content }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500 {{ $isUser ? '' : 'justify-end' }}">
                                        <span>{{ $isUser ? 'Client' : ($isAgent ? 'Conseiller'.(($meta['agent'] ?? null) ? ' : '.$meta['agent'] : '') : ($isSystem ? 'Système' : 'Assistant')) }}, {{ $m->created_at->format('H:i') }}</span>
                                        @if (! empty($meta['voice'])) <x-badge>Voix</x-badge> @endif
                                        @if ($m->role === 'assistant')
                                            @if ($m->isUngrounded()) <x-badge tone="amber">Sans information</x-badge>
                                            @elseif (array_key_exists('grounded', $meta)) <x-badge tone="green">Dans les connaissances</x-badge> @endif
                                            @if ($reason && $reason !== 'no_context') <x-badge :tone="in_array($reason, array_merge(ChatQuery::UNSERVED, ChatQuery::ERRORS)) ? 'red' : 'gray'">{{ ChatDiagnosis::REASONS[$reason] ?? $reason }}</x-badge> @endif
                                            @if (! empty($meta['model'])) <span>{{ $meta['model'] }}</span> @endif
                                            @if (! empty($meta['latency_ms'])) <span>{{ N::decimal($meta['latency_ms'] / 1000) }} s</span> @endif
                                            @if (($meta['feedback'] ?? null) === 'up') <x-badge tone="green">Avis positif</x-badge> @endif
                                            @if (($meta['feedback'] ?? null) === 'down') <x-badge tone="red">Avis négatif</x-badge> @endif
                                        @endif
                                        @if ($meta['delivery_error'] ?? null) <x-badge tone="red">Non livré</x-badge> @endif
                                    </div>
                                    @if ($sources->isNotEmpty())
                                        <p class="mt-0.5 text-[11px] text-slate-400 {{ $isUser ? '' : 'text-right' }}">Sources : {{ $sources->implode(' ; ') }}</p>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="py-8 text-center text-sm text-slate-500">Cette conversation ne contient aucun message.</p>
                        @endforelse
                    </div>
                </section>
            </div>

            <aside class="min-w-0 space-y-6">
                {{-- Contact --}}
                <section class="surface space-y-1.5 p-5 text-sm">
                    <h3 class="font-display font-bold text-brand-950">Qui</h3>
                    <p class="text-slate-900">{{ $conversation->contact_name ?: $who }}</p>
                    @if ($phone) <p class="text-slate-600">{{ $showContact ? $phone : ChatQuery::maskPhone($phone) }}</p> @endif
                    @if ($conversation->contact_email) <p class="break-all text-slate-600">{{ $showContact ? $conversation->contact_email : preg_replace('/^(.).*(@.*)$/', '$1•••$2', $conversation->contact_email) }}</p> @endif
                    @if (! $showContact && ($phone || $conversation->contact_email)) <p class="text-xs text-slate-500">Coordonnées d'un client de {{ $companyName ?? 'cette entreprise' }} : partiellement masquées.</p> @endif
                    <p class="pt-1 text-xs text-slate-500">Dernier message du client : {{ $conversation->last_inbound_at?->locale('fr')->diffForHumans() ?? '–' }}</p>
                    @if ($conversation->meta['handoff_reason'] ?? null) <p class="text-xs text-accent-700">Transfert demandé : {{ $conversation->meta['handoff_reason'] }}</p> @endif
                    @if ($conversation->workspace)
                        <p class="pt-1 text-xs"><a class="font-medium text-brand-700 underline" href="{{ route('admin.chats.index', ['vue' => 'clients', 'client' => $conversation->workspace_id]) }}">Toutes les conversations de {{ $companyName }}</a></p>
                    @endif
                </section>

                {{-- Suivi --}}
                <section class="surface space-y-3 p-5">
                    <h3 class="font-display font-bold text-brand-950">Suivi par l'équipe</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <form method="POST" action="{{ route('admin.chats.flag', $conversation->id) }}">@csrf
                            <button class="w-full rounded-lg border px-3 py-2 text-left text-sm hover:bg-slate-50 {{ $flagged ? 'border-red-300 bg-red-50' : 'border-slate-300' }}">
                                <span class="font-medium text-brand-950">{{ $flagged ? 'Retirer le signalement' : 'Signaler' }}</span>
                                <span class="block text-xs text-slate-600">{{ $flagged ? 'Elle quitte « À surveiller ».' : 'La mettre dans « À surveiller ».' }}</span>
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.chats.review', $conversation->id) }}">@csrf
                            <button class="w-full rounded-lg border px-3 py-2 text-left text-sm hover:bg-slate-50 {{ $reviewed ? 'border-emerald-300 bg-emerald-50' : 'border-slate-300' }}">
                                <span class="font-medium text-brand-950">{{ $reviewed ? 'Retirer « examinée »' : 'Marquer examinée' }}</span>
                                <span class="block text-xs text-slate-600">Pour ne pas la relire.</span>
                            </button>
                        </form>
                    </div>
                </section>

                {{-- Résumé --}}
                <section class="surface space-y-3 p-5">
                    <h3 class="font-display font-bold text-brand-950">Résumé</h3>
                    @if ($summary)
                        <div class="whitespace-pre-line text-sm text-slate-700">{{ $summary->body }}</div>
                        <p class="text-xs text-slate-500">{{ ($summary->meta['source'] ?? '') === 'ia' ? 'Fait par l\'IA' : 'Résumé simplifié (sans IA)' }}, {{ $summary->created_at->locale('fr')->diffForHumans() }}@if ($summary->author), à la demande de {{ $summary->author->name }}@endif.</p>
                    @else
                        <p class="text-sm text-slate-600">Un résumé en quelques puces : ce que voulait la personne, ce qui a bloqué, la suite conseillée.</p>
                    @endif
                    <form method="POST" action="{{ route('admin.chats.summarize', $conversation->id) }}">@csrf
                        <button class="btn-outline w-full justify-center">{{ $summary ? 'Refaire le résumé' : 'Résumer avec l\'IA' }}</button>
                    </form>
                    <p class="text-xs text-slate-500">Un appel à l'IA de la plateforme, jamais compté dans le volume du client. Rien n'est envoyé au client.</p>
                </section>

                {{-- Demandes --}}
                @if ($leads->isNotEmpty())
                    <section class="surface space-y-2 p-5 text-sm">
                        <h3 class="font-display font-bold text-brand-950">Demandes enregistrées</h3>
                        @foreach ($leads as $lead)
                            <div class="rounded-lg bg-slate-50 px-3 py-2">
                                <p><x-badge tone="{{ $lead->isOpen() ? 'amber' : 'green' }}">{{ $lead->label() }}</x-badge> <span class="text-xs text-slate-500">{{ $lead->isOpen() ? 'à traiter' : 'traitée' }}</span></p>
                                <p class="mt-1 text-slate-800">{{ $lead->title }}</p>
                                @if ($lead->summary && $lead->summary !== $lead->title) <p class="text-xs text-slate-600">{{ $lead->summary }}</p> @endif
                            </div>
                        @endforeach
                    </section>
                @endif

                {{-- Notes --}}
                <section class="surface space-y-3 p-5">
                    <h3 class="font-display font-bold text-brand-950">Notes de l'équipe</h3>
                    @forelse ($notes as $note)
                        <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                            <p class="whitespace-pre-line break-words text-slate-800">{{ $note->body }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $note->author?->name ?? 'Équipe' }}, {{ $note->created_at->locale('fr')->diffForHumans() }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-slate-600">Aucune note.</p>
                    @endforelse
                    <form method="POST" action="{{ route('admin.chats.note', $conversation->id) }}" class="space-y-2">@csrf
                        <label for="note" class="sr-only">Nouvelle note</label>
                        <textarea id="note" name="body" rows="3" maxlength="2000" required class="field" placeholder="Une remarque pour l'équipe (jamais visible du client)"></textarea>
                        <button class="btn-primary">Ajouter la note</button>
                    </form>
                </section>

                {{-- Même personne --}}
                @if ($related->isNotEmpty())
                    <section class="surface space-y-2 p-5 text-sm">
                        <h3 class="font-display font-bold text-brand-950">Autres conversations de la même personne</h3>
                        <ul class="divide-y divide-slate-100">
                            @foreach ($related as $other)
                                <li><a href="{{ route('admin.chats.show', $other->id) }}" class="flex items-center justify-between gap-3 py-2 hover:text-brand-700">
                                    <span class="truncate">N° {{ $other->id }}, {{ ChatFilters::CHANNELS[$other->channel] ?? $other->channel }}</span>
                                    <span class="shrink-0 text-xs text-slate-500">{{ $other->last_message_at?->locale('fr')->diffForHumans() }}</span>
                                </a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>
