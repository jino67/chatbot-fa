@php
    $editing = $plan->exists;
    $checkbox = 'rounded border-slate-300 text-brand-600 focus:ring-brand-500';
@endphp
<x-app-layout title="{{ $editing ? 'Modifier '.$plan->name : 'Nouvelle offre' }} | {{ $brand['name'] }}">
    <x-slot name="header">
        <a href="{{ route('admin.plans.index') }}" class="text-sm text-slate-500 hover:text-brand-700">Offres et tarifs</a>
        <div class="mt-1"><x-page-header :title="$editing ? 'Modifier l\'offre '.$plan->name : 'Nouvelle offre'" /></div>
    </x-slot>

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <form method="POST" action="{{ $editing ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="space-y-6">
            @csrf
            @if ($editing) @method('PUT') @endif

            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Identité et prix</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="name" value="Nom affiché" />
                        <input id="name" name="name" required class="field" value="{{ old('name', $plan->name) }}">
                    </div>
                    @unless ($editing)
                        <div>
                            <x-input-label for="slug" value="Identifiant (définitif)" />
                            <input id="slug" name="slug" required class="field font-mono" placeholder="pro" value="{{ old('slug') }}">
                            <p class="mt-1 text-xs text-slate-500">Minuscules, chiffres, tirets. Ne pourra plus être changé.</p>
                        </div>
                    @endunless
                </div>
                <div>
                    <x-input-label for="tagline" value="Accroche" />
                    <input id="tagline" name="tagline" class="field" value="{{ old('tagline', $plan->tagline) }}" placeholder="Pour une PME qui reçoit beaucoup de demandes">
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-700">Prix par période, dans chaque devise</p>
                    <p class="mt-1 text-xs text-slate-500">Le FCFA sert de référence. Une devise laissée vide n'est pas proposée : le client voit alors le prix en FCFA. Repères : 1 euro = 655,957 FCFA = 491,968 KMF (parités fixes) ; le dollar varie, à revoir de temps en temps.</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-4">
                        @foreach (\App\Support\Currency::ALL as $code => $currency)
                            <div>
                                <x-input-label for="price-{{ $code }}" :value="$currency['name'].' ('.$currency['symbol'].')'" />
                                <input id="price-{{ $code }}" name="prices[{{ $code }}]" type="number" min="0" @required($code === 'XOF') class="field" value="{{ old('prices.'.$code, $plan->priceIn($code)) }}">
                                <x-input-error :messages="$errors->get('prices.'.$code)" class="mt-1" />
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="period_months" value="Période (mois)" />
                        <input id="period_months" name="period_months" type="number" min="1" max="12" required class="field" value="{{ old('period_months', $plan->period_months ?? 1) }}">
                    </div>
                    <div>
                        <x-input-label for="trial_days" value="Durée de l'essai gratuit (jours)" />
                        <input id="trial_days" name="trial_days" type="number" min="1" max="365" class="field" value="{{ old('trial_days', $plan->trial_days) }}" placeholder="Vide : sans limite">
                        <p class="mt-1 text-xs text-slate-500">Pour l'offre gratuite : après ce délai, l'assistant se met en pause jusqu'au choix d'une offre payante. Laissez vide pour une offre payante.</p>
                    </div>
                </div>
            </section>

            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Quotas</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach (\App\Models\Plan::limitFields() as $field)
                        <div>
                            <x-input-label for="limit-{{ $field['key'] }}" :value="$field['label']" />
                            <input id="limit-{{ $field['key'] }}" name="limits[{{ $field['key'] }}]" type="number" min="0" required class="field" value="{{ old('limits.'.$field['key'], $plan->limit($field['key'])) }}">
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="surface space-y-4 p-6">
                <h2 class="font-display text-lg font-bold">Options incluses</h2>
                @foreach (\App\Models\Plan::featureFields() as $f)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="features[{{ $f['key'] }}]" value="1" class="{{ $checkbox }}" @checked(old('features.'.$f['key'], $plan->feature($f['key'])))>
                        {{ $f['label'] }}
                    </label>
                @endforeach
            </section>

            <section class="surface space-y-4 p-6">
                <h2 class="font-display text-lg font-bold">Affichage</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="sort" value="Ordre d'affichage" />
                        <input id="sort" name="sort" type="number" min="0" required class="field" value="{{ old('sort', $plan->sort ?? 10) }}">
                    </div>
                    <div class="flex flex-col justify-end gap-3">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_public" value="1" class="{{ $checkbox }}" @checked(old('is_public', $plan->is_public))> Visible sur la page des tarifs</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_highlighted" value="1" class="{{ $checkbox }}" @checked(old('is_highlighted', $plan->is_highlighted))> Mise en avant « Populaire »</label>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_default" value="1" class="{{ $checkbox }}" @checked(old('is_default', $plan->is_default))> Offre par défaut (inscriptions et fin d'abonnement)</label>
                    </div>
                </div>
            </section>

            <div class="flex justify-end gap-3">
                <a href="{{ route('admin.plans.index') }}" class="btn-outline">Annuler</a>
                <button class="btn-primary px-6">Enregistrer l'offre</button>
            </div>
        </form>
    </div>
</x-app-layout>
