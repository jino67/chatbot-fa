@php
    $audience = (array) ($campaign->audience ?? ['type' => 'all']);
    $editing = $campaign->exists;
    $state = [
        'title' => old('title', $campaign->title ?? ''),
        'body' => old('body', $campaign->body ?? ''),
        'type' => old('audience_type', $audience['type'] ?? 'all'),
        'scheduled' => old('when') === 'later',
        'brand' => $brand['name'],
        'estimateUrl' => route('admin.notifications.estimate'),
        'testUrl' => route('admin.notifications.test'),
        'token' => csrf_token(),
    ];
@endphp
<x-app-layout title="{{ $editing ? 'Modifier la notification' : 'Nouvelle notification' }} | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header :title="$editing ? 'Modifier la notification' : 'Nouvelle notification'" subtitle="Écrivez le message, choisissez à qui il s'adresse, vérifiez l'aperçu, puis envoyez ou programmez.">
            <x-slot name="actions">
                <a href="{{ route('admin.notifications.index') }}" class="btn-outline">Retour</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <form method="POST" action="{{ $editing ? route('admin.notifications.update', $campaign) : route('admin.notifications.store') }}"
          x-data="campaignForm(@js($state))" x-init="init()" class="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-5 lg:px-8">
        @csrf
        @if ($editing) @method('PUT') @endif
        {{-- La touche Entrée valide le premier bouton du formulaire : que ce soit « Enregistrer le brouillon », jamais « Envoyer ». --}}
        <button type="submit" name="when" value="draft" class="hidden" tabindex="-1" aria-hidden="true"></button>

        <div class="space-y-6 lg:col-span-3">
            {{-- Message --}}
            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold text-brand-950">Le message</h2>

                <div>
                    <x-input-label for="title" value="Titre" />
                    <input id="title" name="title" x-model="title" value="{{ $state['title'] }}" maxlength="65" required class="field" placeholder="Ex. -20 % sur l'offre Pro jusqu'à dimanche">
                    <p class="mt-1 flex justify-between text-xs text-slate-500"><span>Court et précis : c'est ce qu'on lit en premier.</span><span x-text="title.length + ' / 65'"></span></p>
                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="body" value="Message" />
                    <textarea id="body" name="body" x-model="body" rows="3" maxlength="178" required class="field" placeholder="Une ou deux phrases. Dites ce que le client y gagne.">{{ $state['body'] }}</textarea>
                    <p class="mt-1 flex justify-between text-xs text-slate-500"><span>Au-delà de 178 caractères, le téléphone coupe le texte.</span><span x-text="body.length + ' / 178'"></span></p>
                    <x-input-error :messages="$errors->get('body')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="url" value="Où mène la notification" />
                    <select x-ref="quick" @change="$refs.url.value = $refs.quick.value" class="field">
                        @foreach ($quickLinks as $path => $label)
                            <option value="{{ $path }}" @selected(old('url', $campaign->url) === $path || ($path === '' && ! old('url', $campaign->url)))>{{ $label }}</option>
                        @endforeach
                    </select>
                    <input id="url" name="url" x-ref="url" value="{{ old('url', $campaign->url) }}" class="field mt-2" placeholder="ou une page : /billing, ou un lien https://...">
                    <x-input-error :messages="$errors->get('url')" class="mt-1" />
                </div>

                <fieldset>
                    <legend class="text-sm font-medium text-slate-700">Type de message</legend>
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="kind" value="promo" class="mt-0.5 text-brand-600 focus:ring-brand-500" @checked(old('kind', $campaign->kind ?? 'promo') === 'promo')>
                            <span><strong class="text-brand-950">Promotion ou nouveauté</strong><br><span class="text-slate-600">Respecte les choix des clients : un message par jour au plus, jamais la nuit, et ceux qui refusent les promotions ne le reçoivent pas.</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm has-[:checked]:border-red-400 has-[:checked]:bg-red-50">
                            <input type="radio" name="kind" value="important" class="mt-0.5 text-red-600 focus:ring-red-500" @checked(old('kind', $campaign->kind ?? 'promo') === 'important')>
                            <span><strong class="text-brand-950">Message important</strong><br><span class="text-slate-600">Information de service (panne, changement de tarif, maintenance). Reçu par tous, même ceux qui refusent les promotions. À réserver aux vraies urgences.</span></span>
                        </label>
                    </div>
                </fieldset>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="also_email" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('also_email', $campaign->also_email ?? false))>
                    <span><strong class="font-semibold text-brand-950">Envoyer aussi par e-mail</strong><br><span class="text-slate-600">Utile pour les clients sans l'application installée. À réserver aux messages qui comptent.</span></span>
                </label>
            </section>

            {{-- Audience --}}
            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold text-brand-950">À qui</h2>

                <div class="space-y-2">
                    @foreach ($types as $key => $label)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                            <input type="radio" name="audience_type" value="{{ $key }}" x-model="type" @change="estimate()" class="text-brand-600 focus:ring-brand-500">
                            <span class="font-medium text-brand-950">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div x-show="type === 'plans'" x-cloak>
                    <p class="text-sm font-medium text-slate-700">Offres concernées</p>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach ($plans as $plan)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="plans[]" value="{{ $plan->slug }}" @change="estimate()" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(in_array($plan->slug, old('plans', $audience['plans'] ?? [])))> {{ $plan->name }}</label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('plans')" class="mt-1" />
                </div>

                <div x-show="type === 'workspaces'" x-cloak x-data="{ q: '' }">
                    <p class="text-sm font-medium text-slate-700">Entreprises concernées</p>
                    <input x-model="q" type="search" class="field" placeholder="Chercher une entreprise">
                    <div class="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-3">
                        @foreach ($workspaces as $workspace)
                            <label class="flex items-center gap-2 text-sm" x-show="q === '' || @js(mb_strtolower($workspace->name)).includes(q.toLowerCase())">
                                <input type="checkbox" name="workspaces[]" value="{{ $workspace->id }}" @change="estimate()" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(in_array($workspace->id, array_map('intval', old('workspaces', $audience['workspaces'] ?? []))))>
                                {{ $workspace->name }} <span class="text-xs text-slate-400">{{ $workspace->plan }}</span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('workspaces')" class="mt-1" />
                </div>

                <div x-show="type === 'inactive'" x-cloak>
                    <x-input-label for="inactive_days" value="Absents depuis (jours)" />
                    <input id="inactive_days" name="inactive_days" type="number" min="7" max="180" value="{{ old('inactive_days', $audience['days'] ?? 14) }}" @input.debounce.500ms="estimate()" class="field sm:w-40">
                    <p class="mt-1 text-xs text-slate-500">D'après la mesure d'audience : un client qui ne s'est pas connecté depuis ce nombre de jours reçoit le message. Un bon moyen de le faire revenir.</p>
                </div>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="only_push" value="1" @change="estimate()" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('only_push', $audience['only_push'] ?? false))>
                    <span><strong class="font-semibold text-brand-950">Seulement ceux qui ont activé les notifications</strong><br><span class="text-slate-600">Les autres ne reçoivent le message que dans la cloche de l'application, à leur prochaine visite.</span></span>
                </label>

                <div class="rounded-xl bg-brand-50/70 p-4 text-sm" aria-live="polite">
                    <p x-show="! est" class="text-slate-600">Calcul du nombre de personnes touchées...</p>
                    <p x-show="est" x-cloak class="text-slate-700">
                        <strong class="text-brand-950" x-text="est ? est.users : 0"></strong> personne(s) dans <strong class="text-brand-950" x-text="est ? est.workspaces : 0"></strong> entreprise(s),
                        dont <strong class="text-brand-950" x-text="est ? est.with_push : 0"></strong> joignable(s) sur téléphone (<span x-text="est ? est.devices : 0"></span> appareil(s)).
                    </p>
                </div>
            </section>

            {{-- Quand --}}
            <section class="surface space-y-4 p-6">
                <h2 class="font-display text-lg font-bold text-brand-950">Quand</h2>
                <label class="flex items-center gap-3 text-sm">
                    <input type="checkbox" x-model="scheduled" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span class="font-medium text-brand-950">Programmer l'envoi</span>
                </label>
                <div x-show="scheduled" x-cloak>
                    <x-input-label for="scheduled_at" value="Date et heure d'envoi (heure de la plateforme)" />
                    <input id="scheduled_at" name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at', $campaign->scheduled_at?->format('Y-m-d\TH:i')) }}" class="field sm:w-72" :required="scheduled" :disabled="! scheduled">
                    <x-input-error :messages="$errors->get('scheduled_at')" class="mt-1" />
                    <p class="mt-1 text-xs text-slate-500">Le meilleur moment pour la plupart des clients : en fin de matinée ou en début de soirée.</p>
                </div>
            </section>
        </div>

        {{-- Aperçu et boutons --}}
        <aside class="space-y-4 lg:col-span-2">
            <div class="lg:sticky lg:top-6 space-y-4">
                <section class="surface p-5">
                    <h2 class="font-display text-base font-bold text-brand-950">Aperçu sur le téléphone</h2>
                    <div class="mt-3 rounded-[1.6rem] bg-slate-800 p-3">
                        <div class="rounded-2xl bg-white/95 p-3 shadow-lg">
                            <div class="flex items-center gap-2 text-[0.7rem] text-slate-500">
                                <img src="{{ asset('icon-192.png') }}" alt="" class="h-5 w-5 rounded-md">
                                <span x-text="brand"></span><span>, maintenant</span>
                            </div>
                            <p class="mt-1.5 text-sm font-semibold leading-snug text-slate-900" x-text="title || 'Le titre de votre notification'"></p>
                            <p class="mt-0.5 text-[0.82rem] leading-snug text-slate-600" x-text="body || 'Le message apparaît ici, tel que vos clients le verront.'"></p>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">Le compteur de l'icône de l'application augmente de un pour chaque message non lu.</p>
                </section>

                <section class="surface space-y-3 p-5">
                    <button type="button" class="btn-outline w-full" :disabled="testing || ! title || ! body" @click="sendTest()"><x-icon name="phone" class="h-4 w-4" /> M'envoyer un essai</button>
                    <p class="text-xs text-slate-600" x-show="testMessage" x-text="testMessage" role="status"></p>

                    <template x-if="! scheduled">
                        <button type="submit" name="when" value="now" class="btn-primary w-full" onclick="return confirm('Envoyer cette notification maintenant ?')"><x-icon name="megaphone" class="h-4 w-4" /> Envoyer maintenant</button>
                    </template>
                    <template x-if="scheduled">
                        <button type="submit" name="when" value="later" class="btn-primary w-full"><x-icon name="megaphone" class="h-4 w-4" /> Programmer l'envoi</button>
                    </template>
                    <button type="submit" name="when" value="draft" class="btn-outline w-full">Enregistrer le brouillon</button>
                </section>
            </div>
        </aside>
    </form>

    @once
        <script>
            function campaignForm(state) {
                return {
                    ...state,
                    est: null,
                    testing: false,
                    testMessage: '',
                    init() { this.$nextTick(() => this.estimate()); },
                    async estimate() {
                        const form = this.$root;
                        const data = new FormData(form);
                        // En modification, le formulaire porte « _method=PUT » : sans ce retrait, l'estimation serait lue comme un PUT (erreur 405).
                        data.delete('_method');
                        this.est = null;
                        try {
                            const response = await fetch(this.estimateUrl, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.token }, credentials: 'same-origin' });
                            if (response.ok) this.est = await response.json();
                        } catch (error) { /* l'estimation est un confort */ }
                    },
                    async sendTest() {
                        this.testing = true;
                        this.testMessage = '';
                        try {
                            const data = new FormData();
                            data.append('title', this.title);
                            data.append('body', this.body);
                            data.append('url', this.$refs.url.value);
                            const response = await fetch(this.testUrl, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.token }, credentials: 'same-origin' });
                            const result = await response.json().catch(() => ({}));
                            this.testMessage = result.message || 'Essai impossible pour le moment.';
                        } catch (error) {
                            this.testMessage = 'Essai impossible pour le moment.';
                        }
                        this.testing = false;
                    },
                };
            }
        </script>
    @endonce
</x-app-layout>
