<x-app-layout title="IA et fournisseurs | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="IA et fournisseurs" subtitle="Ordre de bascule des modèles d'IA : Claude Haiku d'abord, puis OpenAI, puis Llama. Les clés sont chiffrées et jamais réaffichées." />
    </x-slot>

    @php $checkbox = 'rounded border-slate-300 text-brand-600 focus:ring-brand-500'; @endphp

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">

        {{-- Etat courant --}}
        <section class="surface flex flex-wrap items-center justify-between gap-4 p-6">
            <div>
                <p class="text-sm text-slate-600">Fournisseur qui répond en ce moment</p>
                @if ($offline)
                    <p class="font-display text-2xl font-bold text-accent-700">Mode hors ligne</p>
                    <p class="mt-1 max-w-xl text-sm text-slate-600">Aucune clé valide : l'assistant renvoie des extraits de vos sources sans rédaction. Ajoutez une clé ci-dessous.</p>
                @else
                    <p class="font-display text-2xl font-bold text-brand-950">{{ $active?->name ?? 'Aucun fournisseur disponible' }}</p>
                    @if ($active) <p class="mt-1 text-sm text-slate-600">Modèle {{ $active->model }}, environ {{ $active->estimatedCostPerAnswer() !== null ? number_format($active->estimatedCostPerAnswer() * 655, 2, ',', ' ').' FCFA' : 'coût inconnu' }} par réponse.</p> @endif
                @endif
            </div>
            <x-badge :tone="$mode === 'forced' ? 'amber' : 'green'">{{ $mode === 'forced' ? 'Mode manuel : fournisseur épinglé' : 'Mode automatique' }}</x-badge>
        </section>

        {{-- Bascule manuelle --}}
        <section class="surface p-6" x-data="{ mode: '{{ $mode }}' }">
            <h2 class="font-display text-lg font-bold">Bascule manuelle</h2>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">
                En automatique, la chaîne est parcourue dans l'ordre et le fournisseur suivant prend le relais dès que le précédent tombe en panne (crédit épuisé, clé refusée, limite de débit, serveur indisponible).
                Vous pouvez aussi forcer un fournisseur, par exemple pour passer sur Llama avant de recharger un compte.
            </p>
            <form method="POST" action="{{ route('admin.ai.mode') }}" class="mt-5 space-y-4">
                @csrf @method('PUT')
                <div class="flex flex-wrap gap-6 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="mode" value="auto" x-model="mode" class="text-brand-600 focus:ring-brand-500"> Automatique (recommandé)</label>
                    <label class="flex items-center gap-2"><input type="radio" name="mode" value="forced" x-model="mode" class="text-brand-600 focus:ring-brand-500"> Forcer un fournisseur</label>
                </div>
                <div x-show="mode === 'forced'" x-cloak class="space-y-3 rounded-lg bg-slate-50 p-4">
                    <div>
                        <x-input-label for="forced_provider_id" value="Fournisseur à utiliser en premier" />
                        <select id="forced_provider_id" name="forced_provider_id" class="field max-w-sm">
                            <option value="">Choisir…</option>
                            @foreach ($providers as $p)
                                <option value="{{ $p->id }}" @selected($forcedId === $p->id)>{{ $p->name }} ({{ $p->statusLabel() }})</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="strict_forced" value="1" class="{{ $checkbox }} mt-0.5" @checked($strict)>
                        <span>Ne jamais basculer sur un autre fournisseur, même en cas de panne. <span class="text-slate-500">Les réponses échoueront si celui-ci tombe : à réserver aux tests.</span></span>
                    </label>
                </div>
                <button class="btn-primary">Appliquer</button>
            </form>
        </section>

        {{-- Chaine --}}
        <section>
            <h2 class="font-display text-xl font-bold">Chaîne de fournisseurs</h2>
            <div class="mt-4 space-y-4">
                @foreach ($providers as $p)
                    <article class="surface p-6" x-data="{ edit: false }">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-sm font-bold text-brand-700" title="Ordre de priorité">{{ $p->priority }}</span>
                                    <h3 class="font-display text-lg font-bold">{{ $p->name }}</h3>
                                    <x-badge :tone="$p->statusTone()">{{ $p->statusLabel() }}</x-badge>
                                    @if ($active && $active->id === $p->id) <x-badge tone="brand">Actif</x-badge> @endif
                                    @if ($mode === 'forced' && $forcedId === $p->id) <x-badge tone="amber">Épinglé</x-badge> @endif
                                </div>
                                <p class="mt-2 text-sm text-slate-600">
                                    Modèle <span class="font-mono text-slate-800">{{ $p->model }}</span>, clé : {{ $p->keyHint() }}
                                    @if ($p->estimatedCostPerAnswer() !== null), environ {{ number_format($p->estimatedCostPerAnswer() * 655, 2, ',', ' ') }} FCFA par réponse @endif
                                </p>
                                @if ($p->last_error)
                                    <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                                        Dernière erreur ({{ $p->last_error_kind }}, {{ $p->last_error_at?->diffForHumans() }}) : {{ \Illuminate\Support\Str::limit($p->last_error, 220) }}
                                        @if ($p->isCoolingDown()) <br><span class="text-red-700">Mis en pause jusqu'à {{ $p->disabled_until->format('H:i') }}.</span> @endif
                                    </p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <form method="POST" action="{{ route('admin.ai.test', $p) }}">@csrf <button class="btn-outline !px-3 !py-1.5 text-xs">Tester</button></form>
                                @if ($p->isCoolingDown() || $p->status === 'down')
                                    <form method="POST" action="{{ route('admin.ai.reset', $p) }}">@csrf <button class="btn-outline !px-3 !py-1.5 text-xs">Remettre en service</button></form>
                                @endif
                                <button type="button" @click="edit = ! edit" class="font-medium text-brand-600 hover:text-brand-800" x-text="edit ? 'Fermer' : 'Modifier'"></button>
                            </div>
                        </div>

                        <form x-show="edit" x-cloak method="POST" action="{{ route('admin.ai.update', $p) }}" class="mt-5 space-y-4 border-t border-slate-100 pt-5">
                            @csrf @method('PUT')
                            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div><x-input-label for="n-{{ $p->id }}" value="Nom" /><input id="n-{{ $p->id }}" name="name" required class="field" value="{{ $p->name }}"></div>
                                <div><x-input-label for="m-{{ $p->id }}" value="Modèle" /><input id="m-{{ $p->id }}" name="model" required class="field font-mono" value="{{ $p->model }}"></div>
                                <div><x-input-label for="v-{{ $p->id }}" value="Modèle pour les images (facultatif)" /><input id="v-{{ $p->id }}" name="vision_model" class="field font-mono" value="{{ $p->vision_model }}"></div>
                                <div class="sm:col-span-2"><x-input-label for="u-{{ $p->id }}" value="Adresse de l'API" /><input id="u-{{ $p->id }}" name="base_url" class="field font-mono" value="{{ $p->base_url }}" @disabled($p->driver === 'anthropic') placeholder="{{ $p->driver === 'anthropic' ? 'Adresse officielle Anthropic' : '' }}"></div>
                                <div><x-input-label for="p-{{ $p->id }}" value="Priorité (1 = en premier)" /><input id="p-{{ $p->id }}" name="priority" type="number" min="1" max="99" required class="field" value="{{ $p->priority }}"></div>
                                <div><x-input-label for="pi-{{ $p->id }}" value="Prix entrée ($ / million de jetons)" /><input id="pi-{{ $p->id }}" name="price_in" type="number" step="0.01" min="0" class="field" value="{{ $p->price_in }}"></div>
                                <div><x-input-label for="po-{{ $p->id }}" value="Prix sortie ($ / million de jetons)" /><input id="po-{{ $p->id }}" name="price_out" type="number" step="0.01" min="0" class="field" value="{{ $p->price_out }}"></div>
                                <div class="sm:col-span-2 lg:col-span-1">
                                    <x-input-label for="k-{{ $p->id }}" value="Nouvelle clé API" />
                                    <input id="k-{{ $p->id }}" name="api_key" type="password" autocomplete="new-password" class="field font-mono" placeholder="Laisser vide pour conserver">
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-6 text-sm">
                                <label class="flex items-center gap-2"><input type="checkbox" name="enabled" value="1" class="{{ $checkbox }}" @checked($p->enabled)> Activé dans la chaîne</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="supports_vision" value="1" class="{{ $checkbox }}" @checked($p->supports_vision)> Lit les images</label>
                                @if ($p->api_key) <label class="flex items-center gap-2"><input type="checkbox" name="clear_key" value="1" class="{{ $checkbox }}"> Effacer la clé saisie</label> @endif
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <button class="btn-primary">Enregistrer</button>
                            </div>
                        </form>
                        <form x-show="edit" x-cloak method="POST" action="{{ route('admin.ai.destroy', $p) }}" class="mt-3" onsubmit="return confirm('Retirer ce fournisseur de la chaîne ?')">
                            @csrf @method('DELETE')
                            <button class="text-sm font-medium text-red-600 hover:text-red-800">Retirer de la chaîne</button>
                        </form>
                    </article>
                @endforeach
            </div>
        </section>

        {{-- Ajout --}}
        <section class="surface p-6">
            <h2 class="font-display text-lg font-bold">Ajouter un fournisseur</h2>
            <p class="mt-1 text-sm text-slate-600">Llama est gratuit à télécharger, mais son exécution ne l'est pas : il faut un hébergeur (OpenRouter, DeepInfra, Together) facturé au volume, ou votre propre serveur (Ollama).</p>
            <form method="POST" action="{{ route('admin.ai.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" x-data="{ presets: @js($presets), preset: 'openrouter', get p() { return this.presets[this.preset]; } }">
                @csrf
                <div>
                    <x-input-label for="preset" value="Type" />
                    <select id="preset" name="preset" x-model="preset" class="field">
                        @foreach ($presets as $key => $preset) <option value="{{ $key }}">{{ $preset['label'] }}</option> @endforeach
                    </select>
                </div>
                <div><x-input-label for="new-name" value="Nom (facultatif)" /><input id="new-name" name="name" class="field" :placeholder="p.label"></div>
                <div><x-input-label for="new-model" value="Modèle" /><input id="new-model" name="model" class="field font-mono" :placeholder="p.model"></div>
                <div><x-input-label for="new-url" value="Adresse de l'API" /><input id="new-url" name="base_url" class="field font-mono" :placeholder="p.base_url || 'https://…'"></div>
                <div class="sm:col-span-2"><x-input-label for="new-key" value="Clé API" /><input id="new-key" name="api_key" type="password" autocomplete="new-password" class="field font-mono"></div>
                <div class="flex items-end sm:col-span-2 lg:col-span-2"><button class="btn-primary">Ajouter en fin de chaîne</button></div>
            </form>
        </section>

        {{-- Embeddings --}}
        <section class="surface p-6">
            <h2 class="font-display text-lg font-bold">Recherche dans les documents (embeddings)</h2>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">
                Les embeddings transforment vos documents en vecteurs pour retrouver le bon passage. Il n'y a pas de bascule automatique : deux fournisseurs produisent des vecteurs incompatibles.
                Si le moteur est indisponible, la recherche retombe sur les mots exacts, moins fine mais fonctionnelle.
            </p>
            <div class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">
                Moteur actuel : <span class="font-mono">{{ $embeddingCurrent }}</span>.
                @if ($staleChunks > 0)
                    <span class="font-medium text-accent-700">{{ number_format($staleChunks, 0, ',', ' ') }} extrait(s) ont été calculés avec un autre moteur.</span>
                @else
                    Tous les extraits sont à jour.
                @endif
            </div>
            <form method="POST" action="{{ route('admin.ai.embeddings') }}" class="mt-4 grid gap-4 sm:grid-cols-3">
                @csrf @method('PUT')
                <div>
                    <x-input-label for="emb-driver" value="Moteur" />
                    <select id="emb-driver" name="driver" class="field">
                        <option value="hashing" @selected($embeddingDriver === 'hashing')>Local, gratuit (moins précis)</option>
                        <option value="voyage" @selected($embeddingDriver === 'voyage')>Voyage AI</option>
                        <option value="openai" @selected($embeddingDriver === 'openai')>OpenAI</option>
                    </select>
                </div>
                <div><x-input-label for="emb-model" value="Modèle (facultatif)" /><input id="emb-model" name="model" class="field font-mono" value="{{ $embeddingModel }}"></div>
                <div><x-input-label for="emb-key" value="Clé API" /><input id="emb-key" name="api_key" type="password" autocomplete="new-password" class="field font-mono" placeholder="{{ $embeddingKeySet ? 'Clé enregistrée : laisser vide pour conserver' : '' }}"></div>
                <div class="sm:col-span-3 flex flex-wrap gap-3">
                    <button class="btn-primary">Enregistrer</button>
                </div>
            </form>
            <form method="POST" action="{{ route('admin.ai.reindex') }}" class="mt-3">
                @csrf
                <button class="btn-outline">Recalculer les vecteurs</button>
            </form>
        </section>
    </div>
</x-app-layout>
