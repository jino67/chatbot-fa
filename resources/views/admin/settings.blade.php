<x-app-layout title="Paramètres | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Paramètres de la plateforme" subtitle="Marque, contact, vitrine, paiement, mentions légales et connexion Facebook." />
    </x-slot>

    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
        <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
            @csrf @method('PUT')

            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Marque</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="brand_name" value="Nom du produit" />
                        <input id="brand_name" name="brand_name" required class="field" value="{{ old('brand_name', $values['brand.name'] ?: $brand['name']) }}">
                        <p class="mt-1 text-xs text-slate-500">Nom provisoire : il s'affiche partout (site, e-mails, « Propulsé par »).</p>
                    </div>
                    <div>
                        <x-input-label for="brand_url" value="Adresse du site" />
                        <input id="brand_url" name="brand_url" type="url" class="field" value="{{ old('brand_url', $values['brand.url']) }}" placeholder="https://kouma.exemple">
                    </div>
                </div>
                <div>
                    <x-input-label for="brand_tagline" value="Accroche" />
                    <input id="brand_tagline" name="brand_tagline" class="field" value="{{ old('brand_tagline', $values['brand.tagline']) }}" placeholder="{{ config('brand.tagline') }}">
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="brand_email" value="E-mail de contact et de notification" />
                        <input id="brand_email" name="brand_email" type="email" class="field" value="{{ old('brand_email', $values['brand.email']) }}">
                        <p class="mt-1 text-xs text-slate-500">Reçoit les demandes d'offre et d'activation WhatsApp.</p>
                    </div>
                    <div>
                        <x-input-label for="brand_whatsapp" value="Numéro WhatsApp commercial" />
                        <input id="brand_whatsapp" name="brand_whatsapp" class="field" value="{{ old('brand_whatsapp', $values['brand.whatsapp']) }}" placeholder="+226 70 00 00 00">
                        <p class="mt-1 text-xs text-slate-500">Affiche un lien « Parler à un conseiller » sur le site.</p>
                    </div>
                </div>
            </section>

            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Vitrine</h2>
                <div>
                    <x-input-label for="landing_bot_key" value="Assistant de démonstration de la page d'accueil" />
                    <select id="landing_bot_key" name="landing_bot_key" class="field">
                        <option value="">Aucun</option>
                        @foreach ($bots as $bot)
                            <option value="{{ $bot->public_key }}" @selected(old('landing_bot_key', $values['marketing.landing_bot_key']) === $bot->public_key)>{{ $bot->name }} ({{ $bot->workspace?->name }})</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Ce widget apparaît sur la page d'accueil : c'est votre meilleure démonstration. Choisissez un assistant bien rempli.</p>
                </div>
            </section>

            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Paiement</h2>
                <div>
                    <x-input-label for="billing_instructions" value="Comment vos clients vous paient" />
                    <textarea id="billing_instructions" name="billing_instructions" rows="5" class="field" placeholder="Orange Money : 70 00 00 00 (au nom de …)&#10;Moov Money : 76 00 00 00&#10;Indiquez le nom de votre entreprise en référence.">{{ old('billing_instructions', $values['billing.instructions']) }}</textarea>
                    <p class="mt-1 text-xs text-slate-500">Affiché sur la page Abonnement de chaque client. Le paiement en ligne automatique n'est pas branché : vous enregistrez les paiements reçus depuis la fiche du client.</p>
                </div>
            </section>

            <section class="surface space-y-5 p-6">
                <h2 class="font-display text-lg font-bold">Mentions légales</h2>
                <p class="text-sm text-slate-600">Ces informations remplissent les pages Conditions et Confidentialité. Faites relire ces textes par un juriste avant la mise en production.</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div><x-input-label for="legal_company" value="Raison sociale" /><input id="legal_company" name="legal_company" class="field" value="{{ old('legal_company', $values['legal.company']) }}"></div>
                    <div><x-input-label for="legal_registration" value="Immatriculation (RCCM, IFU…)" /><input id="legal_registration" name="legal_registration" class="field" value="{{ old('legal_registration', $values['legal.registration']) }}"></div>
                    <div><x-input-label for="legal_address" value="Adresse" /><input id="legal_address" name="legal_address" class="field" value="{{ old('legal_address', $values['legal.address']) }}"></div>
                    <div><x-input-label for="legal_email" value="E-mail légal" /><input id="legal_email" name="legal_email" type="email" class="field" value="{{ old('legal_email', $values['legal.email']) }}"></div>
                </div>
            </section>

            <section class="surface space-y-5 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="font-display text-lg font-bold">Connexion Facebook (facultatif)</h2>
                    <x-badge :tone="$facebookConfigured ? 'green' : 'gray'">{{ $facebookConfigured ? 'Configurée' : 'Non configurée' }}</x-badge>
                </div>
                <p class="text-sm text-slate-600">
                    Permet à un client d'importer sa page Facebook en un clic, avec son accord, via l'API officielle de Meta. Elle exige une application Meta validée
                    (autorisation « pages_read_engagement » examinée par Meta). Sans elle, vos clients collent le contenu de leur page : cela fonctionne déjà.
                </p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div><x-input-label for="facebook_app_id" value="Identifiant de l'application" /><input id="facebook_app_id" name="facebook_app_id" class="field font-mono" value="{{ old('facebook_app_id', $values['facebook.app_id']) }}"></div>
                    <div><x-input-label for="facebook_app_secret" value="Clé secrète de l'application" /><input id="facebook_app_secret" name="facebook_app_secret" type="password" autocomplete="new-password" class="field font-mono" placeholder="{{ $facebookSecretSet ? 'Enregistrée : laisser vide pour conserver' : '' }}"></div>
                </div>
                <p class="text-xs text-slate-500">URL de redirection à déclarer chez Meta : <code class="rounded bg-slate-100 px-1">{{ $callbackUrl }}</code></p>
            </section>

            <div class="flex justify-end"><button class="btn-primary px-6 py-3">Enregistrer les paramètres</button></div>
        </form>
    </div>
</x-app-layout>
