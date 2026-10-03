@use('App\Models\CustomerContact')
@use('App\Services\Users\UserFilters')
@use('App\Support\StatsFormat', 'N')
@php
    $tone = ['amber' => 'amber', 'blue' => 'blue', 'green' => 'green', 'red' => 'red', 'gray' => 'gray', 'brand' => 'indigo'];
    $stopped = $person->crm_status === 'stop';
    $segmentKey = $stage['key'] === 'payant' ? 'payants' : $stage['key'];
    $stageHint = UserFilters::SEGMENTS[$segmentKey][1] ?? '';
    $dueFollowUp = $person->crm_next_follow_up_at?->isPast() && ! in_array($person->crm_status, ['stop', 'perdu', 'client']);
    $dot = ['brand' => 'bg-brand-500', 'green' => 'bg-emerald-500', 'blue' => 'bg-sky-500', 'gray' => 'bg-slate-300'];
    $pickerTemplates = collect($templates)->map(fn ($t) => ['subject' => $t['subject'], 'email' => $t['email'], 'whatsapp' => $t['whatsapp']])->all();
@endphp
<x-app-layout title="{{ $person->name }} | Utilisateurs | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="{{ $person->name }}" subtitle="{{ $person->email }}{{ $workspace ? ' : '.$workspace->name : '' }}. Inscrit {{ $person->created_at->locale('fr')->isoFormat('D MMMM YYYY') }}.">
            <x-slot name="actions">
                <a href="{{ route('admin.people.index') }}" class="btn-outline">Retour à la liste</a>
                @if ($workspace)
                    <form method="POST" action="{{ route('admin.workspaces.enter', $workspace) }}">@csrf
                        <button class="btn-primary" title="Ouvrir son espace pour l'aider">Entrer dans son espace</button>
                    </form>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        @if (session('status')) <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div> @endif
        @if (session('error')) <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800" role="alert">{{ session('error') }}</div> @endif
        @if ($errors->any()) <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800" role="alert">{{ $errors->first() }}</div> @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="min-w-0 space-y-6 lg:col-span-2">

                {{-- Où en est la personne --}}
                <section class="surface">
                    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-6 py-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-display text-lg font-bold text-brand-950">{{ $stage['label'] }}</h2>
                                @foreach ($stage['signals'] as $signal) <x-badge :tone="$tone[$signal['tone']] ?? 'gray'">{{ $signal['label'] }}</x-badge> @endforeach
                                @if ($person->crm_status && $person->crm_status !== 'nouveau') <x-badge :tone="$stopped ? 'red' : 'indigo'">{{ $crm[$person->crm_status] ?? $person->crm_status }}</x-badge> @endif
                                @if ($dueFollowUp) <x-badge tone="red">À relancer</x-badge> @endif
                            </div>
                            <p class="mt-1 max-w-xl text-sm text-slate-600">{{ $stageHint }}</p>
                        </div>
                        @unless ($stopped)
                            <a href="#contacter" class="btn-primary text-sm" @click="$dispatch('contact-template', '{{ $suggested }}')">Écrire : {{ $templates[$suggested]['label'] ?? 'message' }}</a>
                        @endunless
                    </div>
                    <dl class="grid grid-cols-2 gap-4 px-6 py-4 text-sm sm:grid-cols-4">
                        <div><dt class="text-slate-500">Assistants</dt><dd class="font-semibold text-brand-950">{{ $facts->bots_count }}</dd></div>
                        <div><dt class="text-slate-500">Connaissances</dt><dd class="font-semibold text-brand-950">{{ $facts->sources_count }}</dd></div>
                        <div><dt class="text-slate-500">Vrais clients</dt><dd class="font-semibold text-brand-950">{{ $facts->conversations_count }} conversation(s)</dd></div>
                        <div><dt class="text-slate-500">Essais</dt><dd class="font-semibold text-brand-950">{{ $facts->tests_count }}</dd></div>
                        @if ($usage)
                            <div><dt class="text-slate-500">Réponses IA ce mois</dt><dd class="font-semibold text-brand-950">{{ N::number($usage['messages']) }} <span class="font-normal text-slate-500">/ {{ N::number($usage['limit']) }}</span></dd></div>
                        @endif
                        @if ($activity)
                            <div><dt class="text-slate-500">Visites (30 j)</dt><dd class="font-semibold text-brand-950">{{ N::number($activity['sessions']) }}, {{ $activity['active_days'] }} jour(s)</dd></div>
                            <div><dt class="text-slate-500">Santé du compte</dt><dd><x-badge :tone="['good' => 'green', 'ok' => 'blue', 'warn' => 'amber', 'bad' => 'red'][$activity['health']['tone']] ?? 'gray'">{{ $activity['health']['label'] }} ({{ $activity['health']['score'] }})</x-badge></dd></div>
                        @endif
                    </dl>
                </section>

                {{-- Assistants --}}
                @if (count($bots))
                    <x-stats.card title="Ses assistants">
                        <div class="overflow-x-auto"><table class="min-w-full text-sm">
                            <thead class="text-left text-slate-500"><tr><th class="px-6 py-3 font-medium">Assistant</th><th class="px-3 py-3 text-right font-medium">Connaissances</th><th class="px-3 py-3 text-right font-medium">Conversations</th><th class="px-6 py-3 font-medium">Dernier message</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($bots as $bot)
                                    <tr>
                                        <td class="px-6 py-3"><span class="font-medium text-slate-900">{{ $bot->name }}</span> @unless ($bot->is_active) <x-badge tone="amber">En pause</x-badge> @endunless
                                            <a href="{{ route('chat.public', $bot->public_key) }}" target="_blank" rel="noopener" class="ms-1 text-xs text-brand-700 underline">lien de discussion</a></td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ $bot->sources }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums">{{ $bot->conversations }}</td>
                                        <td class="whitespace-nowrap px-6 py-3 text-xs text-slate-500">{{ $bot->last_message ? \Illuminate\Support\Carbon::parse($bot->last_message)->locale('fr')->diffForHumans() : 'aucun' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                        <p class="border-t border-slate-100 px-6 py-3 text-xs text-slate-500">Pour lire ses conversations : <a href="{{ route('admin.chats.index', ['vue' => 'clients', 'client' => $workspace?->id]) }}" class="font-medium text-brand-700 underline">supervision des conversations</a>.</p>
                    </x-stats.card>
                @endif

                {{-- Parcours --}}
                <x-stats.card title="Son parcours" hint="Tiré de ce qui existe vraiment dans l'application : exact même pour les comptes d'avant la mesure.">
                    <ol class="space-y-0 px-6 py-5">
                        @foreach ($journey as $event)
                            <li class="relative flex gap-3 pb-4 last:pb-0">
                                <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $dot[$event['tone']] ?? $dot['gray'] }}" aria-hidden="true"></span>
                                <div class="min-w-0"><p class="text-sm text-slate-800">{{ $event['label'] }}</p><p class="text-xs text-slate-500">{{ $event['at']->locale('fr')->isoFormat('D MMM YYYY [à] HH:mm') }}, {{ $event['at']->locale('fr')->diffForHumans() }}</p></div>
                            </li>
                        @endforeach
                    </ol>
                </x-stats.card>

                {{-- Contacts passés --}}
                <x-stats.card title="Les contacts" hint="Tout ce que l'équipe a fait avec cette personne : qui, quand, par quel moyen, avec quel résultat.">
                    @forelse ($contacts as $contact)
                        <div class="border-b border-slate-100 px-6 py-4 last:border-0">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <x-badge :tone="$contact->channel === 'note' ? 'gray' : 'indigo'">{{ CustomerContact::CHANNELS[$contact->channel] ?? $contact->channel }}</x-badge>
                                @if ($contact->outcome) <x-badge :tone="in_array($contact->outcome, ['interested', 'converted', 'replied']) ? 'green' : 'gray'">{{ CustomerContact::OUTCOMES[$contact->outcome] ?? $contact->outcome }}</x-badge> @endif
                                <span class="text-xs text-slate-500">{{ $contact->staff?->name ?? 'Équipe' }}, {{ $contact->created_at->locale('fr')->isoFormat('D MMM YYYY [à] HH:mm') }}</span>
                            </div>
                            @if ($contact->subject) <p class="mt-1.5 text-sm font-medium text-slate-800">{{ $contact->subject }}</p> @endif
                            @if ($contact->body) <p class="mt-1 whitespace-pre-line break-words text-sm text-slate-600">{{ \Illuminate\Support\Str::limit($contact->body, 600) }}</p> @endif
                        </div>
                    @empty
                        <p class="px-6 py-8 text-center text-sm text-slate-500">Personne ne l'a encore contacté.</p>
                    @endforelse
                </x-stats.card>
            </div>

            <aside class="min-w-0 space-y-6">

                {{-- Contacter --}}
                <section id="contacter" class="surface scroll-mt-24 space-y-4 p-5"
                         x-data="contactPanel(@js($pickerTemplates), @js($suggested), @js($stopped))" @contact-template.window="pick($event.detail)">
                    <h3 class="font-display font-bold text-brand-950">Contacter</h3>

                    @if ($stopped)
                        <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">Cette personne a demandé à ne plus être contactée. Seule une note est possible.</p>
                    @endif
                    @if ($relay)
                        <p class="rounded-lg border border-accent-300 bg-accent-50 px-3 py-2 text-xs text-accent-800">Adresse masquée par Apple : un e-mail n'arrive que si le domaine d'envoi est déclaré chez Apple. Préférez WhatsApp ou la notification.</p>
                    @endif

                    <div class="grid grid-cols-4 gap-1 rounded-xl bg-slate-100 p-1 text-xs font-medium" role="tablist">
                        @foreach (['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'push' => 'Notif.', 'note' => 'Noter'] as $key => $label)
                            <button type="button" role="tab" @click="channel = '{{ $key }}'; fill()" :aria-selected="channel === '{{ $key }}'" :class="channel === '{{ $key }}' ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-600 hover:text-brand-800'" class="rounded-lg px-2 py-1.5 transition" @if ($stopped && $key !== 'note') disabled @endif>{{ $label }}</button>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('admin.people.contact', $person->id) }}" :target="channel === 'whatsapp' ? '_blank' : '_self'" class="space-y-3">
                        @csrf
                        <input type="hidden" name="channel" :value="channel">
                        <input type="hidden" name="template" :value="template">

                        <div x-show="channel !== 'note'" x-cloak>
                            <label for="tpl" class="text-xs font-medium text-slate-600">Modèle</label>
                            <select id="tpl" x-model="template" @change="fill()" class="field">
                                @foreach ($templates as $key => $t)
                                    <option value="{{ $key }}">{{ $t['label'] }}@if ($key === $suggested) (conseillé)@endif</option>
                                @endforeach
                            </select>
                        </div>

                        <div x-show="channel === 'email' || channel === 'push'" x-cloak>
                            <label for="subject" class="text-xs font-medium text-slate-600" x-text="channel === 'push' ? 'Titre (65 caractères)' : 'Objet'"></label>
                            <input id="subject" name="subject" x-model="subject" maxlength="190" class="field" :disabled="channel !== 'email' && channel !== 'push'">
                        </div>

                        <div>
                            <label for="body" class="text-xs font-medium text-slate-600" x-text="channel === 'note' ? 'Votre note' : (channel === 'whatsapp' ? 'Message (WhatsApp s\'ouvre avec ce texte)' : 'Message')"></label>
                            <textarea id="body" name="body" x-model="body" rows="9" maxlength="5000" class="field"></textarea>
                            <p class="mt-1 text-xs text-slate-500" x-show="channel === 'email'" x-cloak>« Bonjour {{ \Illuminate\Support\Str::of($person->name)->before(' ') }}, » est ajouté tout seul. Un bouton vers son espace peut accompagner le message.</p>
                            <p class="mt-1 text-xs text-slate-500" x-show="channel === 'push'" x-cloak>{{ $devices }} appareil(s) activé(s). Sans appareil, le message reste dans sa cloche.</p>
                        </div>

                        <details class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                            <summary class="cursor-pointer text-xs font-medium text-slate-600">Après ce contact : résultat, statut, relance</summary>
                            <div class="mt-3 space-y-3">
                                <div>
                                    <label for="outcome" class="text-xs font-medium text-slate-600">Résultat</label>
                                    <select id="outcome" name="outcome" class="field"><option value="">Pas encore de réponse</option>@foreach (CustomerContact::OUTCOMES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                                </div>
                                <div>
                                    <label for="status" class="text-xs font-medium text-slate-600">Statut du suivi</label>
                                    <select id="status" name="status" class="field"><option value="">Ne pas changer</option>@foreach ($crm as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select>
                                </div>
                                <div>
                                    <label for="follow_up" class="text-xs font-medium text-slate-600">Relancer le</label>
                                    <input id="follow_up" name="follow_up" type="date" min="{{ now()->toDateString() }}" class="field">
                                </div>
                            </div>
                        </details>

                        <button class="btn-primary w-full justify-center" x-text="channel === 'email' ? 'Envoyer l\'e-mail' : (channel === 'whatsapp' ? 'Ouvrir WhatsApp et consigner' : (channel === 'push' ? 'Envoyer la notification' : 'Ajouter la note'))">Envoyer</button>
                    </form>

                    @if ($whatsappNumber || $person->phone)
                        <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-3 text-sm">
                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $person->phone ?: $workspace?->phone) }}" class="font-medium text-brand-700 underline">Appeler</a>
                            <form method="POST" action="{{ route('admin.people.contact', $person->id) }}" class="inline">@csrf
                                <input type="hidden" name="channel" value="call"><input type="hidden" name="body" value="Appel passé.">
                                <button class="text-slate-600 underline hover:text-brand-800" @disabled($stopped)>J'ai appelé (consigner)</button>
                            </form>
                        </div>
                    @endif
                </section>

                {{-- Suivi --}}
                <section class="surface space-y-3 p-5">
                    <h3 class="font-display font-bold text-brand-950">Suivi</h3>
                    <form method="POST" action="{{ route('admin.people.crm', $person->id) }}" class="space-y-3">
                        @csrf @method('PUT')
                        <div>
                            <label for="crm_status" class="text-xs font-medium text-slate-600">Où en est la relation</label>
                            <select id="crm_status" name="status" class="field">@foreach ($crm as $k => $label)<option value="{{ $k }}" @selected(($person->crm_status ?: 'nouveau') === $k)>{{ $label }}</option>@endforeach</select>
                        </div>
                        <div>
                            <label for="crm_follow_up" class="text-xs font-medium text-slate-600">Prochaine relance</label>
                            <input id="crm_follow_up" name="follow_up" type="date" value="{{ $person->crm_next_follow_up_at?->toDateString() }}" class="field">
                        </div>
                        <div>
                            <label for="crm_owner" class="text-xs font-medium text-slate-600">Responsable</label>
                            <select id="crm_owner" name="owner" class="field"><option value="">Personne</option>@foreach ($staff as $member)<option value="{{ $member->id }}" @selected($person->crm_owner_id === $member->id)>{{ $member->name }}</option>@endforeach</select>
                        </div>
                        <button class="btn-outline w-full justify-center">Enregistrer le suivi</button>
                    </form>
                    @if ($person->crm_last_contacted_at) <p class="text-xs text-slate-500">Dernier contact : {{ $person->crm_last_contacted_at->locale('fr')->diffForHumans() }}.</p> @endif
                </section>

                {{-- Compte --}}
                <section class="surface space-y-2 p-5 text-sm">
                    <h3 class="font-display font-bold text-brand-950">Compte</h3>
                    <dl class="space-y-1.5">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">E-mail</dt><dd class="break-all text-right text-slate-800">{{ $person->email }} @if ($person->email_verified_at) <x-badge tone="green">vérifié</x-badge> @endif</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Téléphone</dt><dd class="text-right text-slate-800">{{ $person->phone ?: ($workspace?->phone ?: 'non donné') }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Inscription par</dt><dd class="text-right text-slate-800">{{ UserFilters::SOURCES[$person->signupSource()] ?? $person->signupSource() }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Mot de passe</dt><dd class="text-right text-slate-800">{{ $person->has_password ? 'défini' : 'aucun (connexion externe)' }}</dd></div>
                        @if ($socialAccounts->isNotEmpty())
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Reliés</dt><dd class="text-right text-slate-800">{{ $socialAccounts->pluck('provider')->map(fn ($p) => ucfirst($p))->implode(', ') }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Dernière connexion</dt><dd class="text-right text-slate-800">{{ $person->last_login_at?->locale('fr')->diffForHumans() ?? 'jamais' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Application</dt><dd class="text-right text-slate-800">{{ $person->pwa_installed_at ? 'installée' : 'non installée' }}, {{ $devices }} appareil(s) pour les notifications</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">État</dt><dd class="text-right text-slate-800">{{ $person->is_active ? 'actif' : 'désactivé' }}</dd></div>
                    </dl>
                </section>

                @if ($workspace)
                    <section class="surface space-y-2 p-5 text-sm">
                        <h3 class="font-display font-bold text-brand-950">Entreprise</h3>
                        <dl class="space-y-1.5">
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Nom</dt><dd class="text-right"><a href="{{ route('admin.workspaces.show', $workspace) }}" class="font-medium text-brand-700 underline">{{ $workspace->name }}</a></dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Pays</dt><dd class="text-right text-slate-800">{{ $workspace->country ?: 'non donné' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Offre</dt><dd class="text-right text-slate-800">{{ $workspace->planModel()?->name ?? $workspace->plan }} ({{ $workspace->statusLabel() }})</dd></div>
                            @if ($workspace->plan_ends_at) <div class="flex justify-between gap-3"><dt class="text-slate-500">Fin de période</dt><dd class="text-right text-slate-800">{{ $workspace->plan_ends_at->locale('fr')->isoFormat('D MMM YYYY') }}</dd></div> @endif
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Devise</dt><dd class="text-right text-slate-800">{{ $workspace->currency }}</dd></div>
                        </dl>
                        <p class="pt-1 text-xs text-slate-500">Mot de passe, désactivation, offre : sur la <a href="{{ route('admin.workspaces.show', $workspace) }}" class="underline">fiche de l'espace</a>.</p>
                    </section>
                @endif

                @if ($origin)
                    <section class="surface space-y-2 p-5 text-sm">
                        <h3 class="font-display font-bold text-brand-950">D'où elle vient</h3>
                        <dl class="space-y-1.5">
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Première visite</dt><dd class="text-right text-slate-800">{{ $origin['at']->locale('fr')->isoFormat('D MMM YYYY') }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Source</dt><dd class="text-right text-slate-800">{{ ucfirst($origin['source']) }}{{ $origin['referrer'] ? ' ('.$origin['referrer'].')' : '' }}</dd></div>
                            @if ($origin['campaign']) <div class="flex justify-between gap-3"><dt class="text-slate-500">Campagne</dt><dd class="text-right text-slate-800">{{ $origin['campaign'] }}</dd></div> @endif
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Page d'entrée</dt><dd class="truncate text-right text-slate-800">{{ $origin['page'] ?: '/' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Appareil</dt><dd class="text-right text-slate-800">{{ ucfirst($origin['device']) }}{{ $origin['os'] ? ', '.$origin['os'] : '' }}{{ $origin['country'] ? ', '.strtoupper($origin['country']) : '' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Visites avant et après</dt><dd class="text-right text-slate-800">{{ $origin['visits'] }}</dd></div>
                        </dl>
                    </section>
                @endif
            </aside>
        </div>
    </div>

    @once
        <script>
            function contactPanel(templates, suggested, stopped) {
                return {
                    channel: stopped ? 'note' : 'email',
                    template: suggested,
                    subject: '',
                    body: '',
                    init() { this.fill(); },
                    pick(key) { if (templates[key]) { this.template = key; if (this.channel === 'note') this.channel = 'email'; this.fill(); } },
                    // Remplit le formulaire avec le modèle choisi, selon le moyen (e-mail, WhatsApp ou notification courte).
                    fill() {
                        const t = templates[this.template];
                        if (this.channel === 'note' || ! t) { if (this.channel === 'note') { this.subject = ''; this.body = ''; } return; }
                        this.subject = (this.channel === 'whatsapp') ? '' : t.subject.slice(0, this.channel === 'push' ? 65 : 190);
                        this.body = this.channel === 'whatsapp' ? t.whatsapp : (this.channel === 'push' ? t.email.split(/\n\n/)[0].slice(0, 170) : t.email);
                    },
                };
            }
        </script>
    @endonce
</x-app-layout>
