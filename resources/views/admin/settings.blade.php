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
                    <div class="sm:col-span-2">
                        <x-input-label for="team_alert_email" value="E-mail de réception des alertes de l'équipe (privé)" />
                        <input id="team_alert_email" name="team_alert_email" type="email" class="field" value="{{ old('team_alert_email', $values['team.alert_email']) }}" placeholder="{{ config('platform.admin_email') ?: 'votre adresse personnelle ou celle de la direction' }}">
                        <p class="mt-1 text-xs text-slate-500"><strong>Jamais affiché.</strong> Reçoit les demandes d'offre, d'option et d'activation WhatsApp, le solde Twilio bas, les pannes d'IA et le résumé du lundi. Vide : l'adresse de contact public est utilisée.</p>
                    </div>
                    <div>
                        <x-input-label for="brand_email" value="E-mail de contact (public)" />
                        <input id="brand_email" name="brand_email" type="email" class="field" value="{{ old('brand_email', $values['brand.email']) }}" placeholder="contact@votre-domaine">
                        <p class="mt-1 text-xs text-slate-500"><strong>Affiché</strong> sur le site, dans les e-mails, les guides et les boutons « Écrivez-nous ». Mettez l'adresse professionnelle de la marque, jamais une adresse personnelle.</p>
                    </div>
                    <div>
                        <x-input-label for="brand_whatsapp" value="Numéro WhatsApp commercial" />
                        <input id="brand_whatsapp" name="brand_whatsapp" class="field" value="{{ old('brand_whatsapp', $values['brand.whatsapp']) }}" placeholder="+226 70 00 00 00">
                        <p class="mt-1 text-xs text-slate-500">Avec l'indicatif du pays. Tant qu'il est vide, le bouton « Écrivez-nous sur WhatsApp » n'apparaît pas sur le site.</p>
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

            <section id="whatsapp" class="surface space-y-5 p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-display text-lg font-bold">WhatsApp : comptes de la plateforme et coûts</h2>
                    <div class="flex gap-1.5">
                        <x-badge :tone="$metaTokenSet ? 'green' : 'gray'">Meta {{ $metaTokenSet ? 'connecté' : 'non renseigné' }}</x-badge>
                        <x-badge :tone="$twilioTokenSet ? 'green' : 'gray'">Twilio {{ $twilioTokenSet ? 'connecté' : 'non renseigné' }}</x-badge>
                    </div>
                </div>
                <p class="text-sm text-slate-600">
                    Les messages WhatsApp sont payés par la plateforme, pas par les clients : tout passe par vos comptes, et la page « Consommation » montre ce que chaque client consomme.
                    <strong>Meta direct est le moins cher</strong> (aucun frais d'intermédiaire) ; Twilio ajoute environ 0,005 $ par message entrant et sortant, mais démarre plus vite.
                    Un canal peut utiliser l'un ou l'autre : laissez ses identifiants vides pour qu'il prenne ceux de la plateforme.
                </p>

                <div>
                    <x-input-label for="whatsapp_provider" value="Fournisseur conseillé pour les nouveaux canaux" />
                    <select id="whatsapp_provider" name="whatsapp_provider" class="field">
                        @foreach (['auto' => 'Automatique : Meta direct si disponible, sinon Twilio', 'meta' => 'Toujours Meta direct', 'twilio' => 'Toujours Twilio'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('whatsapp_provider', $values['whatsapp.provider'] ?? 'auto') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div><x-input-label for="meta_waba_id" value="Meta : identifiant du compte WhatsApp Business (WABA)" /><input id="meta_waba_id" name="meta_waba_id" class="field font-mono" value="{{ old('meta_waba_id', $values['whatsapp.meta.waba_id']) }}"></div>
                    <div><x-input-label for="meta_system_token" value="Meta : jeton d'utilisateur système" /><input id="meta_system_token" name="meta_system_token" type="password" autocomplete="new-password" class="field font-mono" placeholder="{{ $metaTokenSet ? 'Enregistré : laisser vide pour conserver' : '' }}"></div>
                    <div><x-input-label for="twilio_account_sid" value="Twilio : Account SID" /><input id="twilio_account_sid" name="twilio_account_sid" class="field font-mono" value="{{ old('twilio_account_sid', $values['whatsapp.twilio.account_sid']) }}"></div>
                    <div><x-input-label for="twilio_auth_token" value="Twilio : Auth Token" /><input id="twilio_auth_token" name="twilio_auth_token" type="password" autocomplete="new-password" class="field font-mono" placeholder="{{ $twilioTokenSet ? 'Enregistré : laisser vide pour conserver' : '' }}"></div>
                </div>

                <div>
                    <x-input-label for="wallet_alert_below" value="Prévenir par e-mail quand le solde Twilio passe sous (dollars)" />
                    <input id="wallet_alert_below" name="wallet_alert_below" type="number" step="1" min="0" class="field sm:w-48" value="{{ old('wallet_alert_below', $values['wallet.alert_below'] ?? 20) }}">
                </div>

                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-sm font-semibold text-brand-950">Coûts unitaires (dollars)</p>
                    <p class="mt-1 text-xs text-slate-500">Servent à estimer la consommation de chaque client. Les valeurs de Meta sont à vérifier dans la grille tarifaire téléchargeable de Meta ; laissez vide pour reprendre les valeurs par défaut.</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-4">
                        <div><x-input-label for="cost_twilio_fee" value="Twilio, par message" /><input id="cost_twilio_fee" name="cost_twilio_fee" type="number" step="0.0001" min="0" class="field" value="{{ old('cost_twilio_fee', $values['costs.twilio_fee'] ?? config('platform.costs.whatsapp.twilio_fee')) }}"></div>
                        <div><x-input-label for="cost_meta_service" value="Meta, service" /><input id="cost_meta_service" name="cost_meta_service" type="number" step="0.0001" min="0" class="field" value="{{ old('cost_meta_service', $values['costs.meta_service'] ?? config('platform.costs.whatsapp.meta.service')) }}"></div>
                        <div><x-input-label for="cost_meta_utility" value="Meta, utilitaire" /><input id="cost_meta_utility" name="cost_meta_utility" type="number" step="0.0001" min="0" class="field" value="{{ old('cost_meta_utility', $values['costs.meta_utility'] ?? config('platform.costs.whatsapp.meta.utility')) }}"></div>
                        <div><x-input-label for="cost_meta_marketing" value="Meta, marketing" /><input id="cost_meta_marketing" name="cost_meta_marketing" type="number" step="0.0001" min="0" class="field" value="{{ old('cost_meta_marketing', $values['costs.meta_marketing'] ?? config('platform.costs.whatsapp.meta.marketing')) }}"></div>
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div><x-input-label for="rate_usd" value="Dollars pour un euro" /><input id="rate_usd" name="rate_usd" type="number" step="0.0001" min="0.1" class="field" value="{{ old('rate_usd', $values['costs.rate_usd'] ?? config('platform.costs.rates.USD')) }}"></div>
                        <div><x-input-label for="rate_mad" value="Dirhams pour un euro" /><input id="rate_mad" name="rate_mad" type="number" step="0.0001" min="1" class="field" value="{{ old('rate_mad', $values['costs.rate_mad'] ?? config('platform.costs.rates.MAD')) }}"></div>
                    </div>
                </div>
            </section>
            <section id="statistiques" class="surface space-y-5 p-6">
                <div>
                    <h2 class="font-display text-lg font-bold">Statistiques : mesure des visites</h2>
                    <p class="mt-1 text-sm text-slate-600">La plateforme compte elle-même les visites, les clics et l'activité des clients, sans service tiers et sans garder d'adresse IP. Un visiteur qui active « Ne pas suivre » dans son navigateur, ou qui refuse sur la page Confidentialité, n'est jamais mesuré.</p>
                </div>
                <label class="flex items-start gap-3 text-sm">
                    <input type="hidden" name="analytics_enabled" value="0">
                    <input type="checkbox" name="analytics_enabled" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('analytics_enabled', $values['analytics.enabled'] ?? true))>
                    <span><strong class="font-semibold">Mesurer les visites</strong><br><span class="text-slate-600">Décochée, plus rien n'est enregistré et le script de mesure n'est plus chargé.</span></span>
                </label>
                <label class="flex items-start gap-3 text-sm">
                    <input type="hidden" name="analytics_digest" value="0">
                    <input type="checkbox" name="analytics_digest" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('analytics_digest', $values['analytics.digest'] ?? true))>
                    <span><strong class="font-semibold">Recevoir le résumé du lundi par e-mail</strong><br><span class="text-slate-600">Visites, heures de pointe, clients à relancer. Envoyé à l'e-mail de réception des alertes (privé).</span></span>
                </label>
                <div>
                    <x-input-label for="analytics_retention_days" value="Durée de conservation des données (jours)" />
                    <input id="analytics_retention_days" name="analytics_retention_days" type="number" min="30" max="1095" class="field sm:w-48" value="{{ old('analytics_retention_days', $values['analytics.retention_days'] ?? config('analytics.retention_days')) }}">
                    <p class="mt-1 text-xs text-slate-500">Au-delà, les données sont effacées chaque nuit. 400 jours permettent de comparer une année à la précédente.</p>
                </div>
                <p class="text-sm"><a href="{{ route('admin.statistics.index') }}" class="font-semibold text-brand-700 underline">Ouvrir les statistiques</a></p>
            </section>

            <section id="voix" class="surface space-y-5 p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-display text-lg font-bold">Voix : messages vocaux et langues locales</h2>
                    <div class="flex gap-1.5">
                        <x-badge :tone="$speechCloudReady ? 'green' : 'gray'">OpenAI {{ $speechCloudReady ? 'prêt' : 'sans clé' }}</x-badge>
                        <x-badge :tone="filled($values['speech.local_url']) ? 'green' : 'gray'">Serveur libre {{ filled($values['speech.local_url']) ? 'configuré' : 'absent' }}</x-badge>
                    </div>
                </div>
                <p class="text-sm text-slate-600">
                    L'écoute des messages vocaux et les réponses en audio utilisent OpenAI avec la clé déjà saisie pour les réponses (ou celle-ci). Coût indicatif : 0,003 $ par minute écoutée, 0,015 $ par minute dite.
                    Pour le bambara, le dioula, le peul, le wolof et le mooré, branchez un serveur libre compatible OpenAI (voir docs/LANGUES.md) : sans lui, ces langues restent comprises à l'écrit seulement.
                </p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div><x-input-label for="speech_api_key" value="Clé pour la voix (facultatif)" /><input id="speech_api_key" name="speech_api_key" type="password" autocomplete="new-password" class="field font-mono" placeholder="{{ $speechKeySet ? 'Enregistrée : laisser vide pour conserver' : 'Sinon, la clé OpenAI de la plateforme' }}"></div>
                    <div><x-input-label for="speech_stt_model" value="Modèle d'écoute" /><input id="speech_stt_model" name="speech_stt_model" class="field font-mono" placeholder="gpt-4o-mini-transcribe" value="{{ old('speech_stt_model', $values['speech.stt_model']) }}"></div>
                    <div><x-input-label for="speech_tts_model" value="Modèle de voix" /><input id="speech_tts_model" name="speech_tts_model" class="field font-mono" placeholder="gpt-4o-mini-tts" value="{{ old('speech_tts_model', $values['speech.tts_model']) }}"></div>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-sm font-semibold text-brand-950">Serveur libre pour les langues locales (facultatif)</p>
                    <div class="mt-3 grid gap-4 sm:grid-cols-3">
                        <div><x-input-label for="speech_local_url" value="Adresse (…/v1)" /><input id="speech_local_url" name="speech_local_url" type="url" class="field font-mono" placeholder="https://asr.exemple.com/v1" value="{{ old('speech_local_url', $values['speech.local_url']) }}"></div>
                        <div><x-input-label for="speech_local_model" value="Modèle" /><input id="speech_local_model" name="speech_local_model" class="field font-mono" placeholder="bambara-asr" value="{{ old('speech_local_model', $values['speech.local_model']) }}"></div>
                        <div><x-input-label for="speech_local_key" value="Clé (si besoin)" /><input id="speech_local_key" name="speech_local_key" type="password" autocomplete="new-password" class="field font-mono" placeholder="{{ $speechLocalKeySet ? 'Enregistrée' : '' }}"></div>
                    </div>
                </div>

                <div x-data="{ busy: false, result: null, async run() { this.busy = true; this.result = null; try { const r = await fetch('{{ route('admin.settings.voice-test') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } }); this.result = await r.json(); } catch (e) { this.result = { cloud: { ok: false, detail: 'La requête a échoué.' } }; } this.busy = false; } }" class="flex flex-wrap items-start gap-4">
                    <button type="button" class="btn-outline" @click="run()" :disabled="busy"><span x-show="! busy">Tester la voix</span><span x-show="busy" x-cloak>Essai en cours…</span></button>
                    <div class="min-w-0 flex-1 space-y-1 text-sm" x-show="result" x-cloak>
                        <p :class="result?.cloud?.ok ? 'text-emerald-700' : 'text-red-700'" x-text="'OpenAI : ' + (result?.cloud?.detail ?? '')"></p>
                        <p x-show="result?.local" :class="result?.local?.ok ? 'text-emerald-700' : 'text-red-700'" x-text="'Serveur libre : ' + (result?.local?.detail ?? '')"></p>
                    </div>
                </div>
                <p class="text-xs text-slate-500">L'essai dit une phrase puis la réécoute, avec les réglages enregistrés (enregistrez avant de tester).</p>
            </section>

            @php
                $socialDefs = [
                    'google' => ['Google', 'Console Google Cloud, « API et services », « Identifiants », « Créer des identifiants », « ID client OAuth », type « Application Web ».', [
                        ['client_id', 'Identifiant client', false], ['client_secret', 'Code secret du client', true],
                    ]],
                    'apple' => ['Apple', 'Compte Apple Developer (payant), « Identifiers », un « Services ID » avec « Sign in with Apple », puis « Keys » : une clé « Sign in with Apple » dont on télécharge le fichier .p8 une seule fois.', [
                        ['client_id', 'Identifiant de service (Services ID)', false], ['team_id', 'Identifiant d\'équipe (Team ID)', false], ['key_id', 'Identifiant de la clé (Key ID)', false], ['private_key', 'Clé privée (contenu du fichier .p8)', true],
                    ]],
                    'microsoft' => ['Microsoft', 'Portail Azure, « Microsoft Entra ID », « Inscriptions d\'applications », nouvelle inscription : comptes professionnels, scolaires et personnels. Puis « Certificats et secrets », nouveau secret client.', [
                        ['client_id', 'Identifiant de l\'application (client)', false], ['client_secret', 'Valeur du secret client', true], ['tenant', 'Locataire (facultatif : laissez « common »)', false],
                    ]],
                ];
            @endphp
            <section id="connexion-externe" class="surface space-y-6 p-6">
                <input type="hidden" name="social_form" value="1">
                <div>
                    <h2 class="font-display text-lg font-bold">Connexion avec Google, Apple, Microsoft, Facebook</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Vos clients s'inscrivent et se connectent en un geste, sans mot de passe à retenir, puis complètent leur entreprise et leur numéro WhatsApp. Un bouton n'apparaît sur les pages
                        de connexion et d'inscription que pour un fournisseur réglé ci-dessous. Chacun demande de créer une « application » chez lui (gratuit, sauf Apple) et de lui indiquer l'adresse de retour affichée.
                        Les secrets sont chiffrés et jamais réaffichés. Pas à pas : guide du super admin, section « Connexion avec Google, Apple... ».
                    </p>
                </div>

                @foreach ($socialDefs as $key => [$label, $help, $fields])
                    @php $configured = $social['providers'][$key]->isConfigured(); @endphp
                    <div class="space-y-4 rounded-xl border border-slate-200 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-display font-bold text-brand-950">{{ $label }}</h3>
                            <x-badge :tone="$configured ? 'green' : 'gray'">{{ $configured ? 'Actif sur les pages de connexion' : 'Non réglé' }}</x-badge>
                        </div>
                        <p class="text-xs text-slate-500">{{ $help }}</p>
                        <div class="grid gap-5 sm:grid-cols-2">
                            @foreach ($fields as [$field, $fieldLabel, $secret])
                                @php $name = 'social_'.$key.'_'.$field; @endphp
                                <div class="{{ $field === 'private_key' ? 'sm:col-span-2' : '' }}">
                                    <x-input-label for="{{ $name }}" value="{{ $fieldLabel }}" />
                                    @if ($field === 'private_key')
                                        <textarea id="{{ $name }}" name="{{ $name }}" rows="4" autocomplete="off" class="field font-mono text-xs" placeholder="{{ $social['secretSet'][$key] ? 'Enregistrée : laisser vide pour conserver' : '-----BEGIN PRIVATE KEY----- ... -----END PRIVATE KEY-----' }}"></textarea>
                                    @elseif ($secret)
                                        <input id="{{ $name }}" name="{{ $name }}" type="password" autocomplete="new-password" class="field font-mono" placeholder="{{ $social['secretSet'][$key] ? 'Enregistré : laisser vide pour conserver' : '' }}">
                                    @else
                                        <input id="{{ $name }}" name="{{ $name }}" class="field font-mono" value="{{ old($name, $values['social.'.$key.'.'.$field] ?? '') }}">
                                    @endif
                                    @error($name) <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>
                        <p class="text-xs text-slate-500">Adresse de retour à déclarer chez {{ $label }} : <code class="select-all rounded bg-slate-100 px-1">{{ $social['redirects'][$key] }}</code></p>
                        @if ($configured)
                            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="social_{{ $key }}_clear" value="1" class="rounded border-slate-300 text-red-600 focus:ring-red-500"> Retirer {{ $label }} (efface les identifiants et masque le bouton)</label>
                        @endif
                    </div>
                @endforeach

                <div class="space-y-3 rounded-xl border border-slate-200 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-display font-bold text-brand-950">Facebook</h3>
                        <x-badge :tone="$social['providers']['facebook']->isConfigured() ? 'green' : 'gray'">{{ $social['providers']['facebook']->isConfigured() ? 'Actif sur les pages de connexion' : 'Non activé' }}</x-badge>
                    </div>
                    <p class="text-xs text-slate-500">Utilise l'application Meta réglée dans la section « Application Meta » plus bas (identifiant et clé secrète). Avant d'activer : passez l'application en mode « Production », ajoutez le produit « Facebook Login » et déclarez l'adresse de retour ci-dessous.</p>
                    <label class="flex items-start gap-3 text-sm">
                        <input type="hidden" name="social_facebook_login" value="0">
                        <input type="checkbox" name="social_facebook_login" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('social_facebook_login', $social['facebookLogin']))>
                        <span><strong class="font-semibold">Autoriser « Continuer avec Facebook »</strong><br><span class="text-slate-600">{{ $facebookConfigured ? 'L\'application Meta est réglée.' : 'Réglez d\'abord l\'application Meta plus bas.' }}</span></span>
                    </label>
                    <p class="text-xs text-slate-500">Adresse de retour à déclarer chez Meta (« URI de redirection OAuth valides ») : <code class="select-all rounded bg-slate-100 px-1">{{ $social['redirects']['facebook'] }}</code></p>
                </div>
            </section>

            <section class="surface space-y-5 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="font-display text-lg font-bold">Application Meta : pages Facebook (facultatif)</h2>
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
