<x-app-layout title="Alertes | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Alertes" subtitle="Comment voulez-vous être prévenu quand un client confirme une commande ou demande une personne ?" />
    </x-slot>

    @php $canWhatsApp = $workspace->hasFeature('whatsapp'); @endphp

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('alerts.update') }}" class="space-y-6" x-data="{ whatsapp: @js((bool) old('whatsapp', $alerts['whatsapp'])), email: @js((bool) old('email', $alerts['email'])) }">
            @csrf @method('PUT')

            <section class="surface space-y-4 p-6">
                <h2 class="font-display text-lg font-bold">Pour quelles demandes ?</h2>
                <p class="text-sm text-slate-600">Toutes les demandes arrivent dans votre page « Demandes ». Cochez celles qui méritent en plus une alerte.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (\App\Models\Lead::KINDS as $kind => $label)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 transition hover:border-brand-300">
                            <input type="checkbox" name="kinds[]" value="{{ $kind }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(in_array($kind, old('kinds', $alerts['kinds'])))>
                            <span class="text-sm font-medium text-brand-950">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Comment être prévenu ?</h2>

                <div class="flex items-start gap-3 rounded-xl bg-slate-50 p-4">
                    <x-illus name="inbox" class="h-9 w-9 shrink-0" />
                    <div>
                        <p class="font-semibold text-brand-950">Tableau de bord <x-badge tone="green">toujours actif</x-badge></p>
                        <p class="text-sm text-slate-600">Chaque demande apparaît dans « Demandes », avec la pastille du menu.</p>
                    </div>
                </div>

                <div class="flex items-start gap-3 rounded-xl border border-slate-200 p-4">
                    <input type="checkbox" name="push" value="1" class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('push', $alerts['push']))>
                    <div class="min-w-0 flex-1">
                        <span class="block font-semibold text-brand-950">Notification sur le téléphone <x-badge tone="brand">le plus rapide</x-badge></span>
                        <span class="block text-sm text-slate-600">Une notification sur l'écran de chaque membre de l'équipe qui a activé les notifications, avec le nombre de demandes en attente sur l'icône de l'application.</span>
                        <a href="{{ route('notifications.preferences') }}" class="mt-1 inline-block text-sm font-semibold text-brand-700 hover:underline">Activer sur mon téléphone</a>
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border border-slate-200 p-4">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="email" value="1" x-model="email" class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span><span class="block font-semibold text-brand-950">E-mail</span><span class="block text-sm text-slate-600">Un e-mail avec le résumé et un lien pour répondre.</span></span>
                    </label>
                    <div x-show="email" x-cloak class="space-y-4">
                        <div>
                            <x-input-label for="email_to" value="Adresse principale" />
                            <input id="email_to" name="email_to" type="email" class="field" value="{{ old('email_to', $alerts['email_to']) }}" placeholder="{{ $email }}">
                            <x-input-error :messages="$errors->get('email_to')" class="mt-1" />
                            <p class="mt-1 text-xs text-slate-500">Laissez vide pour utiliser {{ $email }} (ou l'adresse choisie ci-dessous).</p>
                        </div>
                        @if ($members->count() > 1)
                            <fieldset>
                                <legend class="text-sm font-medium text-brand-900">Membres de l'équipe qui reçoivent aussi l'e-mail</legend>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    @foreach ($members as $member)
                                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="email_members[]" value="{{ $member->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(in_array($member->id, old('email_members', $alerts['email_members'])))> {{ $member->name }} <span class="truncate text-slate-500">({{ $member->email }})</span></label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                        <div>
                            <x-input-label for="email_extra" value="Autres adresses (une par ligne, trois au plus)" />
                            <textarea id="email_extra" name="email_extra" rows="2" class="field" placeholder="associe@exemple.com">{{ old('email_extra', implode("\n", $alerts['email_extra'])) }}</textarea>
                            <x-input-error :messages="$errors->get('email_extra')" class="mt-1" />
                        </div>
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border border-slate-200 p-4 {{ $canWhatsApp ? '' : 'opacity-75' }}">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="whatsapp" value="1" x-model="whatsapp" @disabled(! $canWhatsApp) class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <span><span class="block font-semibold text-brand-950">WhatsApp</span><span class="block text-sm text-slate-600">Un message sur votre téléphone, envoyé par le numéro de votre assistant.</span></span>
                    </label>
                    @unless ($canWhatsApp)
                        <p class="rounded-lg bg-accent-50 px-3 py-2 text-sm text-accent-900">Les alertes WhatsApp sont comprises dans les offres avec WhatsApp. <a href="{{ route('billing.show') }}" class="font-semibold underline">Voir les offres</a></p>
                    @endunless
                    <x-input-error :messages="$errors->get('whatsapp')" class="mt-1" />
                    <div x-show="whatsapp" x-cloak class="space-y-4">
                        <div>
                            <x-input-label for="whatsapp_number" value="Numéro principal" />
                            <input id="whatsapp_number" name="whatsapp_number" type="tel" class="field sm:w-72" value="{{ old('whatsapp_number', $alerts['whatsapp_number']) }}" placeholder="+226 70 00 00 00">
                            <x-input-error :messages="$errors->get('whatsapp_number')" class="mt-1" />
                        </div>
                        <fieldset>
                            <legend class="text-sm font-medium text-brand-900">Numéros des membres de l'équipe</legend>
                            <p class="mt-0.5 text-xs text-slate-500">Chacun renseigne son numéro dans « Mon profil » et reçoit l'alerte sur son propre téléphone.</p>
                            <div class="mt-2 space-y-1.5">
                                @foreach ($members as $member)
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="whatsapp_members[]" value="{{ $member->id }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(in_array($member->id, old('whatsapp_members', $alerts['whatsapp_members']))) @disabled(! $member->phone && $member->id !== auth()->id())>
                                        {{ $member->id === auth()->id() ? 'Vous' : $member->name }}
                                        @if ($member->phone) <span class="text-slate-500">({{ $member->phone }})</span>
                                        @elseif ($member->id === auth()->id()) <a href="{{ route('profile.edit') }}" class="text-brand-700 underline">ajouter mon numéro au profil</a>
                                        @else <span class="text-slate-400">(numéro non renseigné)</span> @endif
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <div>
                            <x-input-label for="whatsapp_extra" value="Autres numéros (un par ligne, trois au plus)" />
                            <textarea id="whatsapp_extra" name="whatsapp_extra" rows="2" class="field sm:w-72" placeholder="+226 70 11 22 33">{{ old('whatsapp_extra', implode("\n", $alerts['whatsapp_extra'])) }}</textarea>
                            <x-input-error :messages="$errors->get('whatsapp_extra')" class="mt-1" />
                            <p class="mt-1 text-xs text-slate-500">Un associé, un livreur, un responsable : n'importe quel numéro WhatsApp.</p>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                            <p>WhatsApp n'accepte d'écrire à un numéro que dans les <strong>24 heures</strong> qui suivent son dernier message, ou avec un <strong>modèle approuvé</strong>. Pour être alerté à toute heure, faites approuver le modèle d'alerte :</p>
                            <div class="mt-2 flex flex-wrap items-center gap-3">
                                @if ($template?->isApproved())
                                    <x-badge tone="green">Modèle approuvé</x-badge>
                                @elseif ($template)
                                    <x-badge :tone="$template->status === 'REJECTED' ? 'red' : 'amber'">{{ $template->statusLabel() }}</x-badge>
                                @elseif ($channel)
                                    <button type="submit" formaction="{{ route('alerts.template') }}" formmethod="POST" formnovalidate class="btn-outline">Créer le modèle d'alerte</button>
                                @else
                                    <span class="text-slate-500">Disponible dès qu'un canal WhatsApp est actif.</span>
                                @endif
                            </div>
                            @if (! $template?->isApproved())
                                <p class="mt-2 text-xs text-slate-500">En attendant, l'alerte part par message libre quand votre numéro a écrit à l'assistant dans les dernières 24 h (envoyez-lui « Bonjour » pour l'ouvrir).</p>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="surface space-y-4 p-6">
                <h2 class="font-display text-lg font-bold">Rappels</h2>
                <p class="text-sm text-slate-600">Si une demande reste sans réponse, on vous relance, trois fois au plus. Prendre la demande en charge ou lui répondre arrête les rappels.</p>
                <select name="reminder_minutes" class="field sm:w-64">
                    @foreach ($reminders as $minutes => $label)
                        <option value="{{ $minutes }}" @selected((int) old('reminder_minutes', $alerts['reminder_minutes']) === $minutes)>{{ $label }}</option>
                    @endforeach
                </select>
            </section>

            <section class="rounded-2xl border border-dashed border-slate-300 p-6">
                <h2 class="font-display text-base font-bold">Et les étiquettes WhatsApp ?</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Les étiquettes de l'application WhatsApp Business (« Nouveau client », « Commande à confirmer »…) ne peuvent pas être posées par un programme : l'API WhatsApp Cloud ne les propose pas.
                    Kouma les remplace par ses propres étiquettes, visibles dans « Demandes » et dans la boîte de réception, et vous indique à chaque demande celle à poser à la main si vous utilisez aussi l'application.
                </p>
            </section>

            <div class="flex justify-end"><button class="btn-primary px-6 py-3">Enregistrer mes alertes</button></div>
        </form>
    </div>
</x-app-layout>
