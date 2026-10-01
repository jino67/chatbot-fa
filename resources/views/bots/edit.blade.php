<x-bot-layout :bot="$bot" tab="settings">
    <form method="POST" action="{{ route('bots.update', $bot) }}" class="mx-auto max-w-4xl space-y-6">
        @csrf
        @method('PUT')

        <section class="surface space-y-5 p-6">
            <h2 class="font-display text-lg font-bold">Identité</h2>
            <div>
                <x-input-label for="name" value="Nom de l'assistant" />
                <input id="name" name="name" class="field max-w-md" value="{{ old('name', $bot->name) }}" required>
            </div>
            <p class="text-sm text-slate-600">Le ton, le métier et les règles de votre assistant se règlent dans l'onglet <a class="font-medium text-brand-600 underline" href="{{ route('instructions.edit', $bot) }}">Personnalité</a>.</p>
        </section>

        @php
            $workspace = auth()->user()->currentWorkspace();
            $voiceOn = $workspace->hasFeature('voice');
            $usage = app(\App\Services\UsageService::class);
        @endphp
        <section id="langues" class="surface space-y-5 p-6">
            <div>
                <h2 class="font-display text-lg font-bold">Langues et voix</h2>
                <p class="mt-1 text-sm text-slate-600">Choisissez les langues que parle votre assistant, et si vos clients peuvent lui parler avec des messages vocaux. Vous pouvez tout changer à tout moment.</p>
            </div>

            <x-language-picker :selected="old('languages', $bot->spokenLanguages())" :primary="old('language', $bot->language)" />

            <div class="space-y-4 border-t border-slate-100 pt-5">
                <h3 class="flex items-center gap-2 font-semibold text-brand-950"><x-illus name="mic" class="h-6 w-6" /> Messages vocaux</h3>

                @unless ($voiceOn)
                    <p class="rounded-xl bg-accent-50 px-3 py-2 text-sm text-accent-900">Les messages vocaux sont compris dans les offres Pro et Business. <a class="font-semibold underline" href="{{ route('billing.show') }}">Voir les offres</a>. Vos réglages sont conservés et s'appliqueront dès que l'option sera active.</p>
                @else
                    <p class="text-sm text-slate-600">Ce mois-ci : <strong class="text-brand-950">{{ number_format($usage->voiceUsed($workspace), 0, ',', "\u{202F}") }}</strong> sur {{ number_format($usage->voiceAllowance($workspace), 0, ',', "\u{202F}") }} messages vocaux (écoutés ou envoyés).</p>
                @endunless

                <label class="flex items-start gap-3">
                    <input type="checkbox" name="voice_in" value="1" class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('voice_in', $bot->voice_in))>
                    <span>
                        <span class="block text-sm font-medium text-brand-900">Comprendre les messages vocaux</span>
                        <span class="block text-xs text-slate-500">Sur WhatsApp et sur votre site, le vocal du client est transcrit, puis traité comme un message écrit.</span>
                    </span>
                </label>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="voice_out" value="Répondre en audio" />
                        <select id="voice_out" name="voice_out" class="field">
                            @foreach (['never' => 'Jamais : texte seulement', 'mirror' => 'Quand le client m\'envoie un vocal', 'always' => 'Toujours, en plus du texte'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('voice_out', $bot->voice_out) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Le texte de la réponse part toujours : l'audio s'y ajoute. Les réponses longues sont dites en résumé.</p>
                    </div>
                    <div>
                        <x-input-label for="voice_style" value="Voix" />
                        <select id="voice_style" name="voice_style" class="field">
                            @foreach (['feminine' => 'Féminine', 'masculine' => 'Masculine', 'neutral' => 'Neutre'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('voice_style', $bot->voice_style) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">La voix parle français, anglais et arabe. Dans les autres langues, l'assistant répond par écrit.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="surface space-y-5 p-6">
            <h2 class="font-display text-lg font-bold">Messages</h2>
            <div>
                <x-input-label for="welcome_message" value="Message d'accueil" />
                <input id="welcome_message" name="welcome_message" class="field" value="{{ old('welcome_message', $bot->welcome_message) }}" placeholder="{{ $bot->welcome() }}">
            </div>
            <div>
                <x-input-label for="fallback_message" value="Message quand l'assistant ne sait pas" />
                <input id="fallback_message" name="fallback_message" class="field" value="{{ old('fallback_message', $bot->fallback_message) }}" placeholder="{{ $bot->fallback() }}">
            </div>
            <div>
                <x-input-label for="suggested_questions" value="Questions suggérées à l'ouverture (une par ligne, 4 maximum)" />
                <textarea id="suggested_questions" name="suggested_questions" rows="4" class="field">{{ old('suggested_questions', implode("\n", $bot->suggested_questions ?? [])) }}</textarea>
            </div>
            <div>
                <input type="hidden" name="open_chat_shown" value="1">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="open_chat" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('open_chat', $bot->allowsFreeChat()))>
                    <span>
                        <span class="font-medium">Autoriser la conversation libre</span>
                        <span class="block text-xs text-slate-500">L'assistant peut échanger aimablement sur autre chose que vos documents (salutations, nouvelles, questions simples) et dit qui l'a conçu quand on le lui demande. Vos prix, horaires et adresses ne viennent toujours que de vos sources. Chaque réponse compte dans votre volume mensuel ; décochez pour qu'il ne réponde qu'à partir de vos sources.</span>
                    </span>
                </label>
            </div>
        </section>

        <section class="surface space-y-5 p-6">
            <h2 class="font-display text-lg font-bold">Apparence du widget</h2>
            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <x-input-label for="title" value="Titre de la fenêtre" />
                    <input id="title" name="title" class="field" value="{{ old('title', $bot->theme('title', $bot->name)) }}">
                </div>
                <div>
                    <x-input-label for="color" value="Couleur" />
                    <input id="color" name="color" type="color" class="mt-1 h-10 w-full rounded-lg border-slate-300" value="{{ old('color', $bot->theme('color', '#2340D9')) }}">
                </div>
                <div>
                    <x-input-label for="position" value="Position" />
                    <select id="position" name="position" class="field">
                        <option value="right" @selected(old('position', $bot->theme('position', 'right')) === 'right')>En bas à droite</option>
                        <option value="left" @selected(old('position', $bot->theme('position', 'right')) === 'left')>En bas à gauche</option>
                    </select>
                </div>
            </div>
            @unless (auth()->user()->currentWorkspace()->hasFeature('remove_branding'))
                <p class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Votre offre affiche la mention « Propulsé par {{ $brand['name'] }} » en bas du widget. Elle disparaît avec les offres Pro et Business :
                    <a href="{{ route('billing.show') }}" class="font-medium text-brand-600 underline">voir les offres</a>.
                </p>
            @endunless
        </section>

        <section class="surface space-y-5 p-6">
            <h2 class="font-display text-lg font-bold">Sécurité et transfert vers une personne</h2>
            <div>
                <x-input-label for="allowed_origins" value="Sites autorisés à afficher le widget (un par ligne)" />
                <textarea id="allowed_origins" name="allowed_origins" rows="3" class="field" placeholder="https://www.monsite.com&#10;https://*.monsite.com">{{ old('allowed_origins', implode("\n", $bot->allowed_origins ?? [])) }}</textarea>
                <p class="mt-1 text-xs {{ empty($bot->allowed_origins) ? 'font-medium text-accent-700' : 'text-slate-500' }}">
                    @if (empty($bot->allowed_origins))
                        Liste vide : le widget fonctionne sur n'importe quel site. Renseignez vos domaines avant la mise en production.
                    @else
                        Le widget est refusé sur tout autre site.
                    @endif
                </p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="handoff_email" value="E-mail prévenu quand un client demande une personne" />
                    <input id="handoff_email" type="email" name="handoff_email" class="field" value="{{ old('handoff_email', $bot->handoff_email) }}">
                </div>
                <div class="flex flex-col justify-end gap-3">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="collect_contact" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('collect_contact', $bot->collect_contact))>
                        Demander le nom et le téléphone avant la discussion
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('is_active', $bot->is_active))>
                        Assistant actif
                    </label>
                </div>
            </div>
        </section>

        <div class="flex justify-end">
            <button class="btn-primary px-6 py-3">Enregistrer les réglages</button>
        </div>
    </form>

    <div class="mx-auto mt-10 max-w-4xl rounded-xl border border-red-200 bg-white p-6" x-data="{ confirm: false }">
        <h2 class="font-display text-lg font-bold text-red-700">Supprimer cet assistant</h2>
        <p class="mt-1 text-sm text-slate-600">Ses sources, conversations et canaux seront définitivement effacés.</p>
        <form method="POST" action="{{ route('bots.destroy', $bot) }}" class="mt-4">
            @csrf
            @method('DELETE')
            <button type="button" x-show="!confirm" @click="confirm = true" class="text-sm font-medium text-red-600 hover:text-red-800">Supprimer…</button>
            <button type="submit" x-show="confirm" x-cloak class="btn-danger">Oui, tout supprimer</button>
        </form>
    </div>
</x-bot-layout>
