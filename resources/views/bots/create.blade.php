<x-app-layout title="Nouvel assistant | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Créer votre assistant"
                       subtitle="Quelques réponses, et nous rédigeons pour vous la consigne complète de votre assistant : ton, parcours de commande ou de rendez-vous, règles de votre métier. Vous pourrez tout modifier ensuite." />
    </x-slot>

    @php
        $tones = \App\Chat\InstructionGenerator::TONES;
        $formal = \App\Chat\InstructionGenerator::FORMALITY;
        $emojis = \App\Chat\InstructionGenerator::EMOJIS;
        $lengths = \App\Chat\InstructionGenerator::LENGTHS;
        $langs = \App\Chat\InstructionGenerator::LANGUAGES;
        $old = fn ($k) => old($k, $defaults[$k] ?? '');
    @endphp

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('bots.store') }}" class="space-y-6" x-data="{ sector: '{{ old('sector', 'commerce') }}' }">
            @csrf

            <section class="surface p-6">
                <h2 class="font-display text-lg font-bold">1. Votre assistant</h2>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="name" value="Nom de l'assistant" />
                        <input id="name" name="name" class="field" value="{{ old('name') }}" placeholder="Ex. Assistante Boutique Awa" required autofocus>
                        <p class="mt-1 text-xs text-slate-500">Visible par vos clients dans la discussion.</p>
                    </div>
                    <div>
                        <x-input-label for="language" value="Langue principale" />
                        <select id="language" name="language" class="field">
                            <option value="fr" @selected(old('language', 'fr') === 'fr')>Français</option>
                            <option value="en" @selected(old('language') === 'en')>English</option>
                            <option value="ar" @selected(old('language') === 'ar')>العربية</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Il répond toujours dans la langue du client.</p>
                    </div>
                </div>

                <fieldset class="mt-5">
                    <legend class="text-sm font-medium text-brand-900">Votre métier</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($sectors as $slug => $sector)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" :class="sector === '{{ $slug }}' ? 'border-brand-600 bg-brand-50' : 'border-slate-200'">
                                <input type="radio" name="sector" value="{{ $slug }}" x-model="sector" class="mt-1 border-slate-300 text-brand-600 focus:ring-brand-500" required>
                                <span>
                                    <span class="block text-sm font-semibold text-brand-950">{{ $sector['label'] }}</span>
                                    <span class="block text-xs text-slate-500">{{ $sector['exemples'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </section>

            <section class="surface p-6">
                <h2 class="font-display text-lg font-bold">2. Votre entreprise ({{ $company }})</h2>
                <p class="mt-1 text-sm text-slate-600">Tout est facultatif, mais plus vous en dites, plus la consigne est précise.</p>
                <div class="mt-4 space-y-5">
                    <div>
                        <x-input-label for="description" value="Que fait votre entreprise, en une ou deux phrases ?" />
                        <textarea id="description" name="description" rows="2" class="field" placeholder="Ex. Boutique de mode africaine : robes en wax, boubous brodés, accessoires. Retouches gratuites.">{{ $old('description') }}</textarea>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div><x-input-label for="city" value="Ville" /><input id="city" name="city" class="field" value="{{ $old('city') }}" placeholder="Ouagadougou"></div>
                        <div><x-input-label for="country" value="Pays" /><input id="country" name="country" class="field" value="{{ $old('country') }}" placeholder="Burkina Faso"></div>
                        <div><x-input-label for="hours" value="Horaires d'ouverture" /><input id="hours" name="hours" class="field" value="{{ $old('hours') }}" placeholder="Lundi au samedi 8 h à 19 h, dimanche 9 h à 13 h"></div>
                        <div><x-input-label for="phone" value="Téléphone ou WhatsApp" /><input id="phone" name="phone" class="field" value="{{ $old('phone') }}" placeholder="+226 70 00 00 00"></div>
                        <div><x-input-label for="email" value="E-mail de contact" /><input id="email" type="email" name="email" class="field" value="{{ $old('email') }}"></div>
                        <div><x-input-label for="website" value="Site web" /><input id="website" name="website" class="field" value="{{ $old('website') }}" placeholder="www.monsite.com"></div>
                    </div>
                    <div>
                        <x-input-label for="offers" value="Vos produits ou services principaux" />
                        <input id="offers" name="offers" class="field" value="{{ $old('offers') }}" placeholder="Robes, boubous, sacs en cuir, foulards">
                    </div>
                    <div>
                        <x-input-label for="extra_rules" value="Règles particulières (une par ligne)" />
                        <textarea id="extra_rules" name="extra_rules" rows="3" class="field" placeholder="Ex. Ne jamais proposer de remise.&#10;Toujours demander le quartier avant de parler de livraison.">{{ $old('extra_rules') }}</textarea>
                    </div>
                </div>
            </section>

            <section class="surface p-6">
                <h2 class="font-display text-lg font-bold">3. Sa personnalité</h2>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="tone" value="Ton" />
                        <select id="tone" name="tone" class="field">@foreach ($tones as $k => $l)<option value="{{ $k }}" @selected($old('tone') === $k)>{{ $l }}</option>@endforeach</select>
                    </div>
                    <div>
                        <x-input-label for="formality" value="Comment s'adresse-t-il au client ?" />
                        <select id="formality" name="formality" class="field">@foreach ($formal as $k => $l)<option value="{{ $k }}" @selected($old('formality') === $k)>{{ $l }}</option>@endforeach</select>
                    </div>
                    <div>
                        <x-input-label for="emojis" value="Émojis" />
                        <select id="emojis" name="emojis" class="field">@foreach ($emojis as $k => $l)<option value="{{ $k }}" @selected($old('emojis') === $k)>{{ $l }}</option>@endforeach</select>
                    </div>
                    <div>
                        <x-input-label for="length" value="Longueur des réponses" />
                        <select id="length" name="length" class="field">@foreach ($lengths as $k => $l)<option value="{{ $k }}" @selected($old('length') === $k)>{{ $l }}</option>@endforeach</select>
                    </div>
                </div>
                <fieldset class="mt-5">
                    <legend class="text-sm font-medium text-brand-900">Langues de vos clients</legend>
                    <div class="mt-2 flex flex-wrap gap-4">
                        @foreach ($langs as $code => $label)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="languages[]" value="{{ $code }}" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(in_array($code, (array) old('languages', ['fr'])))> {{ ucfirst($label) }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            </section>

            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('bots.index') }}" class="text-sm text-slate-600 hover:text-brand-700">Annuler</a>
                <button class="btn-primary px-6 py-3 text-base">Créer mon assistant</button>
            </div>
        </form>
    </div>
</x-app-layout>
