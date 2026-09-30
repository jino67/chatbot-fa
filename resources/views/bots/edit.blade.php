<x-bot-layout :bot="$bot" tab="settings">
    <form method="POST" action="{{ route('bots.update', $bot) }}" class="mx-auto max-w-4xl space-y-6">
        @csrf
        @method('PUT')

        <section class="surface space-y-5 p-6">
            <h2 class="font-display text-lg font-bold">Identité</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="name" value="Nom de l'assistant" />
                    <input id="name" name="name" class="field" value="{{ old('name', $bot->name) }}" required>
                </div>
                <div>
                    <x-input-label for="language" value="Langue principale" />
                    <select id="language" name="language" class="field">
                        @foreach (['fr' => 'Français', 'en' => 'English', 'ar' => 'العربية'] as $code => $label)
                            <option value="{{ $code }}" @selected(old('language', $bot->language) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="text-sm text-slate-600">Le ton, le métier et les règles de votre assistant se règlent dans l'onglet <a class="font-medium text-brand-600 underline" href="{{ route('instructions.edit', $bot) }}">Personnalité</a>.</p>
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
