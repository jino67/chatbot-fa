<x-bot-layout :bot="$bot" tab="instructions">
    @php
        $tones = \App\Chat\InstructionGenerator::TONES;
        $formal = \App\Chat\InstructionGenerator::FORMALITY;
        $emojis = \App\Chat\InstructionGenerator::EMOJIS;
        $lengths = \App\Chat\InstructionGenerator::LENGTHS;

        $p = fn ($k) => old($k, $profile[$k] ?? '');
    @endphp

    <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">

        {{-- La consigne : le texte que l'assistant suit --}}
        <section class="surface p-6" x-data="polisher({ url: '{{ route('instructions.polish', $bot) }}', text: @js(old('instructions', $bot->instructions)) })">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-xl font-bold">La consigne de votre assistant</h2>
                    <p class="mt-1 max-w-xl text-sm text-slate-600">
                        C'est le texte que votre assistant suit à chaque conversation : son rôle, son ton, ses parcours et ses interdits. Nous l'avons rédigé pour votre métier ; modifiez-le librement.
                        Les règles de fiabilité de la plateforme (ne pas inventer, refuser le hors-sujet) s'appliquent toujours, quoi que vous écriviez.
                    </p>
                </div>
                @if ($bot->hasCustomInstructions())
                    <x-badge tone="amber">Modifiée par vous</x-badge>
                @else
                    <x-badge tone="green">Rédigée pour vous</x-badge>
                @endif
            </div>

            <form method="POST" action="{{ route('instructions.update', $bot) }}" class="mt-5 space-y-4">
                @csrf @method('PUT')
                <textarea name="instructions" rows="24" x-model="text" class="field font-mono text-[13px] leading-relaxed" aria-label="Consigne de l'assistant"></textarea>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-500"><span x-text="text.length"></span> caractères. Une consigne plus longue coûte un peu plus à chaque réponse : restez précis.</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="polish" :disabled="loading" class="btn-outline">
                            <x-icon name="wand" class="h-4 w-4" /> <span x-text="loading ? 'Amélioration…' : 'Améliorer avec l\'IA'"></span>
                        </button>
                        <button class="btn-primary">Enregistrer la consigne</button>
                    </div>
                </div>
                <p x-show="message" x-cloak x-text="message" class="rounded-lg bg-accent-50 px-3 py-2 text-sm text-brand-950"></p>
            </form>

            @if ($bot->instructions_default)
                <form method="POST" action="{{ route('instructions.reset', $bot) }}" class="mt-4 border-t border-slate-100 pt-4"
                      onsubmit="return confirm('Revenir à la consigne d\'origine ? Vos modifications seront perdues.')">
                    @csrf
                    <button class="text-sm font-medium text-slate-600 hover:text-brand-700">Restaurer la consigne d'origine</button>
                </form>
            @endif
        </section>

        {{-- Le profil : la matiere premiere de la consigne --}}
        <section class="surface h-fit p-6">
            <h2 class="font-display text-xl font-bold">Profil de l'entreprise</h2>
            <p class="mt-1 text-sm text-slate-600">Ces informations servent à rédiger la consigne. Cochez « Régénérer » pour réécrire la consigne à partir d'elles.</p>

            <form method="POST" action="{{ route('instructions.profile', $bot) }}" class="mt-5 space-y-4">
                @csrf @method('PUT')
                <div>
                    <x-input-label for="sector" value="Métier" />
                    <select id="sector" name="sector" class="field">@foreach ($sectors as $slug => $s)<option value="{{ $slug }}" @selected(old('sector', $bot->sector) === $slug)>{{ $s['label'] }}</option>@endforeach</select>
                </div>
                <div><x-input-label for="description" value="Activité en une ou deux phrases" /><textarea id="description" name="description" rows="2" class="field">{{ $p('description') }}</textarea></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><x-input-label for="city" value="Ville" /><input id="city" name="city" class="field" value="{{ $p('city') }}"></div>
                    <div><x-input-label for="country" value="Pays" /><input id="country" name="country" class="field" value="{{ $p('country') }}"></div>
                </div>
                <div><x-input-label for="hours" value="Horaires" /><input id="hours" name="hours" class="field" value="{{ $p('hours') }}"></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><x-input-label for="phone" value="Téléphone / WhatsApp" /><input id="phone" name="phone" class="field" value="{{ $p('phone') }}"></div>
                    <div><x-input-label for="email" value="E-mail" /><input id="email" type="email" name="email" class="field" value="{{ $p('email') }}"></div>
                </div>
                <div><x-input-label for="website" value="Site web" /><input id="website" name="website" class="field" value="{{ $p('website') }}"></div>
                <div><x-input-label for="offers" value="Produits et services" /><input id="offers" name="offers" class="field" value="{{ $p('offers') }}"></div>
                <div><x-input-label for="extra_rules" value="Règles particulières (une par ligne)" /><textarea id="extra_rules" name="extra_rules" rows="3" class="field">{{ $p('extra_rules') }}</textarea></div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div><x-input-label for="tone" value="Ton" /><select id="tone" name="tone" class="field">@foreach ($tones as $k => $l)<option value="{{ $k }}" @selected($p('tone') === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div><x-input-label for="formality" value="Adresse au client" /><select id="formality" name="formality" class="field">@foreach ($formal as $k => $l)<option value="{{ $k }}" @selected($p('formality') === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div><x-input-label for="emojis" value="Émojis" /><select id="emojis" name="emojis" class="field">@foreach ($emojis as $k => $l)<option value="{{ $k }}" @selected($p('emojis') === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div><x-input-label for="length" value="Longueur des réponses" /><select id="length" name="length" class="field">@foreach ($lengths as $k => $l)<option value="{{ $k }}" @selected($p('length') === $k)>{{ $l }}</option>@endforeach</select></div>
                </div>
                {{-- Les langues se règlent dans les paramètres : elles restent envoyées pour garder le profil à jour. --}}
                @foreach ($bot->spokenLanguages() as $code)
                    <input type="hidden" name="languages[]" value="{{ $code }}">
                @endforeach
                <p class="text-sm text-slate-600">Langues parlées : <strong class="text-brand-950">{{ collect($bot->spokenLanguages())->map(fn ($c) => \App\Support\Languages::name($c))->implode(', ') }}</strong>. <a class="font-medium text-brand-600 underline" href="{{ route('bots.edit', $bot) }}#langues">Modifier dans les paramètres</a>.</p>

                <label class="flex items-start gap-2 rounded-lg bg-accent-50 p-3 text-sm">
                    <input type="checkbox" name="regenerate" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span><strong>Régénérer la consigne</strong> à partir de ce profil. Le texte actuel sera remplacé.</span>
                </label>

                <button class="btn-primary w-full">Enregistrer le profil</button>
            </form>
        </section>
    </div>

    <script>
        function polisher({ url, text }) {
            return {
                text, loading: false, message: '',
                async polish() {
                    this.loading = true; this.message = '';
                    try {
                        const res = await fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ instructions: this.text }),
                        });
                        const data = await res.json();
                        if (!res.ok) throw new Error(data.message || 'Erreur ' + res.status);
                        if (data.changed) { this.text = data.text; this.message = 'Version améliorée proposée. Relisez-la, puis cliquez sur « Enregistrer la consigne » pour la garder.'; }
                        else { this.message = 'Aucune amélioration proposée : l\'IA n\'est pas configurée ou la consigne est déjà bonne.'; }
                    } catch (e) { this.message = 'Amélioration impossible : ' + e.message; }
                    finally { this.loading = false; }
                },
            };
        }
    </script>
</x-bot-layout>
