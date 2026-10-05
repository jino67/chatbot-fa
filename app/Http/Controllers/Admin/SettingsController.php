<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Services\CostCalculator;
use App\Services\PlatformSettings;
use App\Speech\SpeechException;
use App\Speech\SpeechFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use App\Social\Auth\SocialLogin;
use App\Social\FacebookGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Parametres de la plateforme : marque, contact, vitrine, paiement, mentions legales, application Facebook. */
class SettingsController extends Controller
{
    private const KEYS = [
        'brand.name', 'brand.tagline', 'brand.url', 'brand.whatsapp', 'brand.email', 'team.alert_email',
        'marketing.landing_bot_key',
        'billing.instructions',
        'legal.company', 'legal.address', 'legal.registration', 'legal.email',
        'facebook.app_id',
        'whatsapp.provider', 'whatsapp.meta.waba_id', 'whatsapp.twilio.account_sid', 'whatsapp.twilio.balance_sid', 'wallet.alert_below',
        'costs.twilio_fee', 'costs.meta_service', 'costs.meta_utility', 'costs.meta_marketing', 'costs.rate_usd', 'costs.rate_mad',
        'speech.stt_model', 'speech.tts_model', 'speech.local_url', 'speech.local_model',
        'analytics.enabled', 'analytics.retention_days', 'analytics.digest',
        'social.google.client_id', 'social.apple.client_id', 'social.apple.team_id', 'social.apple.key_id', 'social.microsoft.client_id', 'social.microsoft.tenant',
    ];

    /** Connexion externe : les champs ordinaires et les secrets (jamais réaffichés) de chaque fournisseur. */
    private const SOCIAL_FIELDS = [
        'google' => ['client_id'],
        'apple' => ['client_id', 'team_id', 'key_id'],
        'microsoft' => ['client_id', 'tenant'],
    ];

    private const SOCIAL_SECRETS = ['google' => 'client_secret', 'apple' => 'private_key', 'microsoft' => 'client_secret'];

    public function edit(PlatformSettings $settings, FacebookGraph $facebook, SocialLogin $login)
    {
        return view('admin.settings', [
            'values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $settings->get($k)])->all(),
            'bots' => Bot::withoutGlobalScopes()->with('workspace')->orderBy('name')->get(),
            'facebookConfigured' => $facebook->isConfigured(),
            'facebookSecretSet' => $settings->has('facebook.app_secret'),
            'metaTokenSet' => $settings->has('whatsapp.meta.system_token'),
            'twilioTokenSet' => $settings->has('whatsapp.twilio.auth_token'),
            'twilioBalanceTokenSet' => $settings->has('whatsapp.twilio.balance_token'),
            'speechKeySet' => $settings->has('speech.api_key'),
            'speechLocalKeySet' => $settings->has('speech.local_key'),
            'speechCloudReady' => (bool) app(SpeechFactory::class)->cloudKey(),
            'callbackUrl' => route('facebook.callback'),
            'social' => [
                'providers' => $login->all(),
                'redirects' => collect(array_keys(SocialLogin::PROVIDERS))->mapWithKeys(fn ($key) => [$key => $login->redirectUri($key)])->all(),
                'secretSet' => collect(self::SOCIAL_SECRETS)->mapWithKeys(fn ($field, $provider) => [$provider => $settings->has("social.{$provider}.{$field}")])->all(),
                'facebookLogin' => $login->facebookLoginEnabled(),
            ],
        ]);
    }

    public function update(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:40'],
            'brand_tagline' => ['nullable', 'string', 'max:160'],
            'brand_url' => ['nullable', 'url', 'max:200'],
            'brand_whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-\.]{7,25}$/'],
            'brand_email' => ['nullable', 'email', 'max:190'],
            'team_alert_email' => ['nullable', 'email', 'max:190'],
            'landing_bot_key' => ['nullable', 'string', 'exists:bots,public_key'],
            'billing_instructions' => ['nullable', 'string', 'max:600'],
            'legal_company' => ['nullable', 'string', 'max:160'],
            'legal_address' => ['nullable', 'string', 'max:300'],
            'legal_registration' => ['nullable', 'string', 'max:120'],
            'legal_email' => ['nullable', 'email', 'max:190'],
            'facebook_app_id' => ['nullable', 'string', 'max:40'],
            'facebook_app_secret' => ['nullable', 'string', 'max:120'],
            // WhatsApp : comptes de la plateforme (les messages sont payes par la plateforme, pas par les clients)
            'whatsapp_provider' => ['nullable', 'in:auto,meta,twilio'],
            'meta_waba_id' => ['nullable', 'string', 'max:40'],
            'meta_system_token' => ['nullable', 'string', 'max:1000'],
            'twilio_account_sid' => ['nullable', 'string', 'max:64'],
            'twilio_auth_token' => ['nullable', 'string', 'max:200'],
            'twilio_balance_sid' => ['nullable', 'string', 'max:64'],
            'twilio_balance_token' => ['nullable', 'string', 'max:200'],
            'wallet_alert_below' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'cost_twilio_fee' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'cost_meta_service' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'cost_meta_utility' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'cost_meta_marketing' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'rate_usd' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'rate_mad' => ['nullable', 'numeric', 'min:1', 'max:100'],
            // Voix : moteur OpenAI par défaut, serveur libre facultatif pour les langues locales
            'speech_stt_model' => ['nullable', 'string', 'max:60'],
            'speech_tts_model' => ['nullable', 'string', 'max:60'],
            'speech_api_key' => ['nullable', 'string', 'max:300'],
            'speech_local_url' => ['nullable', 'url', 'max:200'],
            'speech_local_model' => ['nullable', 'string', 'max:80'],
            'speech_local_key' => ['nullable', 'string', 'max:300'],
            // Statistiques : mesure d'audience maison (voir docs/ANALYTICS.md)
            'analytics_enabled' => ['nullable', 'boolean'],
            'analytics_digest' => ['nullable', 'boolean'],
            'analytics_retention_days' => ['nullable', 'integer', 'min:30', 'max:1095'],
            // Connexion avec Google, Apple, Microsoft, Facebook (voir docs/SOCIAL.md)
            'social_google_client_id' => ['nullable', 'string', 'max:200'],
            'social_google_client_secret' => ['nullable', 'string', 'max:200'],
            'social_apple_client_id' => ['nullable', 'string', 'max:200'],
            'social_apple_team_id' => ['nullable', 'string', 'max:40'],
            'social_apple_key_id' => ['nullable', 'string', 'max:40'],
            'social_apple_private_key' => ['nullable', 'string', 'max:4000'],
            'social_microsoft_client_id' => ['nullable', 'string', 'max:200'],
            'social_microsoft_client_secret' => ['nullable', 'string', 'max:300'],
            'social_microsoft_tenant' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9.-]+$/'],
            'social_facebook_login' => ['nullable', 'boolean'],
        ]);

        foreach ([
            'brand.name' => 'brand_name', 'brand.tagline' => 'brand_tagline', 'brand.url' => 'brand_url',
            'brand.whatsapp' => 'brand_whatsapp', 'brand.email' => 'brand_email', 'team.alert_email' => 'team_alert_email',
            'marketing.landing_bot_key' => 'landing_bot_key', 'billing.instructions' => 'billing_instructions',
            'legal.company' => 'legal_company', 'legal.address' => 'legal_address',
            'legal.registration' => 'legal_registration', 'legal.email' => 'legal_email',
            'facebook.app_id' => 'facebook_app_id',
            'whatsapp.provider' => 'whatsapp_provider', 'whatsapp.meta.waba_id' => 'meta_waba_id',
            'whatsapp.twilio.account_sid' => 'twilio_account_sid', 'whatsapp.twilio.balance_sid' => 'twilio_balance_sid', 'wallet.alert_below' => 'wallet_alert_below',
            'costs.twilio_fee' => 'cost_twilio_fee', 'costs.meta_service' => 'cost_meta_service',
            'costs.meta_utility' => 'cost_meta_utility', 'costs.meta_marketing' => 'cost_meta_marketing',
            'costs.rate_usd' => 'rate_usd', 'costs.rate_mad' => 'rate_mad',
            'speech.stt_model' => 'speech_stt_model', 'speech.tts_model' => 'speech_tts_model',
            'speech.local_url' => 'speech_local_url', 'speech.local_model' => 'speech_local_model',
        ] as $key => $field) {
            $settings->set($key, $data[$field] ?? null);
        }

        // Statistiques : ces champs ne sont envoyés que par la section dédiée du formulaire ; absents, les réglages restent.
        foreach (['analytics.enabled' => 'analytics_enabled', 'analytics.digest' => 'analytics_digest'] as $key => $field) {
            if ($request->has($field)) {
                $settings->set($key, $request->boolean($field));
            }
        }
        if ($request->has('analytics_retention_days')) {
            $settings->set('analytics.retention_days', $data['analytics_retention_days'] ?? null);
        }

        // Connexion externe : champs envoyés par la section dédiée seulement. « Effacer » retire un fournisseur en entier.
        if ($request->boolean('social_form')) {
            foreach (self::SOCIAL_FIELDS as $provider => $fields) {
                $clear = $request->boolean("social_{$provider}_clear");
                foreach ($fields as $field) {
                    $settings->set("social.{$provider}.{$field}", $clear ? null : (trim((string) ($data["social_{$provider}_{$field}"] ?? '')) ?: null));
                }
                $secret = self::SOCIAL_SECRETS[$provider];
                if ($clear) {
                    $settings->set("social.{$provider}.{$secret}", null);
                } elseif (filled($data["social_{$provider}_{$secret}"] ?? null)) {
                    $settings->set("social.{$provider}.{$secret}", trim($data["social_{$provider}_{$secret}"]), secret: true);
                }
            }
            $settings->set('social.facebook.login', $request->boolean('social_facebook_login'));
        }

        // Secrets : laisses vides, ils restent tels quels et ne sont jamais reaffiches.
        foreach ([
            'whatsapp.meta.system_token' => 'meta_system_token', 'whatsapp.twilio.auth_token' => 'twilio_auth_token', 'whatsapp.twilio.balance_token' => 'twilio_balance_token',
            'speech.api_key' => 'speech_api_key', 'speech.local_key' => 'speech_local_key',
        ] as $key => $field) {
            if (filled($data[$field] ?? null)) {
                $settings->set($key, $data[$field], secret: true);
            }
        }

        if (filled($data['facebook_app_secret'] ?? null)) {
            $settings->set('facebook.app_secret', $data['facebook_app_secret'], secret: true);
        }

        AuditLog::record('settings.updated');

        return back()->with('status', 'Paramètres enregistrés.');
    }

    /**
     * Essai de bout en bout de la voix, avec la clé réellement configurée : une phrase est dite, puis réécoutée.
     * Le coût est de l'ordre du millième de dollar. Le serveur libre des langues locales est seulement joint.
     */
    public function voiceTest(SpeechFactory $factory, CostCalculator $costs): JsonResponse
    {
        $client = $factory->make();
        $cloud = ['ok' => false, 'detail' => 'Aucune clé OpenAI : renseignez-la ici ou dans « IA et fournisseurs ».'];

        if ($client->canSpeak() && $client->canTranscribe('fr')) {
            $started = microtime(true);

            try {
                $audio = $client->speak('Bonjour, ceci est un test de la voix de la plateforme.', 'feminine', 'fr');
                $heard = $client->transcribe($audio->bytes, $audio->mime, 'fr');
                $cost = $costs->voice('tts', $audio->seconds) + $costs->voice('stt', $heard->seconds);

                $cloud = ['ok' => true, 'detail' => sprintf(
                    'Synthèse (%s, %d s) puis transcription (%s) : « %s ». %d ms, coût estimé %s $.',
                    $audio->engine, $audio->seconds, $heard->engine, $heard->text, (int) ((microtime(true) - $started) * 1000), number_format($cost, 4, ',', ' '),
                )];
            } catch (SpeechException $e) {
                $cloud = ['ok' => false, 'detail' => match ($e->kind) {
                    'auth' => 'Clé refusée par le fournisseur.',
                    'billing' => 'Crédit épuisé ou quota dépassé chez le fournisseur.',
                    'rate_limit' => 'Limite de débit atteinte, réessayez dans une minute.',
                    'network' => 'Le fournisseur ne répond pas.',
                    default => $e->getMessage(),
                }];
            }
        }

        $local = null;
        if ($server = $factory->local()) {
            try {
                $response = Http::timeout(6)->get($server->url('/models'));
                $local = ['ok' => $response->status() < 500, 'detail' => $response->status() < 500
                    ? 'Serveur joignable (HTTP '.$response->status().').'
                    : 'Le serveur répond avec une erreur (HTTP '.$response->status().').'];
            } catch (\Throwable) {
                $local = ['ok' => false, 'detail' => 'Serveur injoignable : vérifiez l\'adresse et qu\'il est démarré.'];
            }
        }

        return response()->json(['cloud' => $cloud, 'local' => $local]);
    }
}
