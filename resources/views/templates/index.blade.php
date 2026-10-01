<x-bot-layout :bot="$bot" tab="channels">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('channels.show', $bot) }}" class="text-sm text-slate-500 hover:text-brand-700">Canaux</a>
            <h2 class="font-display text-xl font-bold">Modèles de messages WhatsApp</h2>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Un modèle est un message préparé et approuvé par WhatsApp. Il permet d'écrire à un client au-delà de 24 heures sans réponse de sa part : relance de panier, suivi de commande, rappel de rendez-vous.
            </p>
        </div>
        @if ($allowed && $channel)
            <form method="POST" action="{{ route('templates.sync', $bot) }}">@csrf
                <button class="btn-outline">Actualiser les statuts</button>
            </form>
        @endif
    </div>

    @if (! $allowed)
        <div class="surface mx-auto max-w-2xl p-10 text-center">
            <h3 class="font-display text-xl font-bold">Les modèles sont inclus dans l'offre Pro</h3>
            <p class="mt-2 text-sm text-slate-600">Créez vos modèles, suivez leur approbation par WhatsApp et utilisez-les depuis les conversations pour relancer vos clients.</p>
            <a href="{{ route('billing.show') }}" class="btn-accent mt-5 px-6 py-3">Voir les offres</a>
        </div>
    @elseif (! $channel)
        <div class="surface mx-auto max-w-2xl p-10 text-center">
            <h3 class="font-display text-xl font-bold">WhatsApp n'est pas encore actif</h3>
            <p class="mt-2 text-sm text-slate-600">Les modèles s'attachent à votre numéro WhatsApp. Demandez son activation, notre équipe technique s'en charge.</p>
            <a href="{{ route('channels.show', $bot) }}" class="btn-primary mt-5">Demander l'activation</a>
        </div>
    @else
        @if ($library)
            @include('templates._library')
        @endif

        <h3 class="mb-3 font-display text-lg font-bold">Vos modèles</h3>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <section class="surface divide-y divide-slate-100">
                @forelse ($templates as $template)
                    <article class="px-6 py-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-mono text-sm font-semibold text-brand-950">{{ $template->name }}</h3>
                                <x-badge :tone="$template->statusTone()">{{ $template->statusLabel() }}</x-badge>
                                <x-badge>{{ strtoupper($template->language) }}</x-badge>
                                <x-badge tone="brand">{{ $template->category }}</x-badge>
                            </div>
                            @if ($canCreate)
                                <form method="POST" action="{{ route('templates.destroy', [$bot, $template]) }}" onsubmit="return confirm('Supprimer ce modèle chez WhatsApp ?')">
                                    @csrf @method('DELETE')
                                    <button class="text-sm font-medium text-red-600 hover:text-red-800">Supprimer</button>
                                </form>
                            @endif
                        </div>
                        <p class="mt-3 whitespace-pre-line rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-800">{{ $template->body }}</p>
                        @if ($template->rejected_reason)
                            <p class="mt-2 text-sm text-red-700">Motif du refus : {{ $template->rejected_reason }}</p>
                        @endif
                        @if ($template->variables_count)
                            <p class="mt-2 text-xs text-slate-500">{{ $template->variables_count }} variable(s). Vous les renseignez au moment de l'envoi, depuis la conversation.</p>
                        @endif
                    </article>
                @empty
                    <div class="px-6 py-12 text-center text-sm text-slate-600">
                        Aucun modèle pour l'instant. @if ($library) Ajoutez un paquet ou quelques modèles de la bibliothèque ci-dessus, ou créez le vôtre avec le formulaire. @elseif ($canCreate) Créez le premier avec le formulaire. @else Créez vos modèles chez votre fournisseur, puis cliquez sur « Actualiser les statuts ». @endif
                    </div>
                @endforelse
            </section>

            @if ($canCreate)
                <section class="surface h-fit p-6" x-data="{ body: @js(old('body', '')), vars: 0, sync() { const m = this.body.match(/\{\{\d+\}\}/g); this.vars = m ? new Set(m).size : 0 } }" x-init="sync()">
                    <h3 class="font-display text-lg font-bold">Un modèle sur mesure</h3>
                    <form method="POST" action="{{ route('templates.store', $bot) }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="t-name" value="Nom technique" />
                            <input id="t-name" name="name" required class="field font-mono" placeholder="suivi_commande" value="{{ old('name') }}">
                            <p class="mt-1 text-xs text-slate-500">Minuscules, chiffres et tirets bas uniquement.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="t-lang" value="Langue" />
                                <select id="t-lang" name="language" class="field">
                                    @foreach (\App\Models\WhatsAppTemplate::LANGUAGES as $code => $label)
                                        <option value="{{ $code }}" @selected(old('language', 'fr') === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="t-cat" value="Catégorie" />
                                <select id="t-cat" name="category" class="field">
                                    <option value="UTILITY" @selected(old('category') === 'UTILITY')>Utilitaire</option>
                                    <option value="MARKETING" @selected(old('category') === 'MARKETING')>Marketing</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <x-input-label for="t-body" value="Message" />
                            <textarea id="t-body" name="body" rows="5" required x-model="body" @input="sync()" class="field" placeholder="Bonjour {{1}}, votre commande {{2}} est en route. Elle arrive demain.">{{ old('body') }}</textarea>
                            <p class="mt-1 text-xs text-slate-500">Variables : {{1}}, {{2}}... Le message ne commence ni ne finit par une variable.</p>
                        </div>
                        <template x-if="vars > 0">
                            <div class="space-y-2 rounded-lg bg-slate-50 p-3">
                                <p class="text-xs font-medium text-slate-700">Un exemple par variable (exigé par WhatsApp)</p>
                                <template x-for="i in vars" :key="i">
                                    <input :name="'body_examples[' + (i - 1) + ']'" class="field" :placeholder="'Exemple pour {{' + i + '}}'" required>
                                </template>
                            </div>
                        </template>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><x-input-label for="t-header" value="Titre (facultatif)" /><input id="t-header" name="header" class="field" value="{{ old('header') }}"></div>
                            <div><x-input-label for="t-footer" value="Bas de message (facultatif)" /><input id="t-footer" name="footer" class="field" value="{{ old('footer') }}"></div>
                        </div>
                        <div>
                            <x-input-label for="t-qr" value="Boutons de réponse rapide (un par ligne, 3 maximum)" />
                            <textarea id="t-qr" name="quick_replies" rows="2" class="field">{{ old('quick_replies') }}</textarea>
                        </div>
                        <details class="text-sm">
                            <summary class="cursor-pointer font-medium text-brand-900">Boutons lien et appel (facultatif)</summary>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <input name="url_text" class="field" placeholder="Texte du bouton lien" value="{{ old('url_text') }}">
                                <input name="url" class="field" placeholder="https://..." value="{{ old('url') }}">
                                <input name="phone_text" class="field" placeholder="Texte du bouton appel" value="{{ old('phone_text') }}">
                                <input name="phone" class="field" placeholder="+22670000000" value="{{ old('phone') }}">
                            </div>
                        </details>
                        <button class="btn-primary w-full">Envoyer à WhatsApp pour approbation</button>
                    </form>
                </section>
            @endif
        </div>
    @endif
</x-bot-layout>
