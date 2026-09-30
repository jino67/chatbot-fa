<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Services\PlatformSettings;
use App\Social\FacebookGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Parametres de la plateforme : marque, contact, vitrine, paiement, mentions legales, application Facebook. */
class SettingsController extends Controller
{
    private const KEYS = [
        'brand.name', 'brand.tagline', 'brand.url', 'brand.whatsapp', 'brand.email',
        'marketing.landing_bot_key',
        'billing.instructions',
        'legal.company', 'legal.address', 'legal.registration', 'legal.email',
        'facebook.app_id',
    ];

    public function edit(PlatformSettings $settings, FacebookGraph $facebook)
    {
        return view('admin.settings', [
            'values' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => $settings->get($k)])->all(),
            'bots' => Bot::withoutGlobalScopes()->with('workspace')->orderBy('name')->get(),
            'facebookConfigured' => $facebook->isConfigured(),
            'facebookSecretSet' => $settings->has('facebook.app_secret'),
            'callbackUrl' => route('facebook.callback'),
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
            'landing_bot_key' => ['nullable', 'string', 'exists:bots,public_key'],
            'billing_instructions' => ['nullable', 'string', 'max:600'],
            'legal_company' => ['nullable', 'string', 'max:160'],
            'legal_address' => ['nullable', 'string', 'max:300'],
            'legal_registration' => ['nullable', 'string', 'max:120'],
            'legal_email' => ['nullable', 'email', 'max:190'],
            'facebook_app_id' => ['nullable', 'string', 'max:40'],
            'facebook_app_secret' => ['nullable', 'string', 'max:120'],
        ]);

        foreach ([
            'brand.name' => 'brand_name', 'brand.tagline' => 'brand_tagline', 'brand.url' => 'brand_url',
            'brand.whatsapp' => 'brand_whatsapp', 'brand.email' => 'brand_email',
            'marketing.landing_bot_key' => 'landing_bot_key', 'billing.instructions' => 'billing_instructions',
            'legal.company' => 'legal_company', 'legal.address' => 'legal_address',
            'legal.registration' => 'legal_registration', 'legal.email' => 'legal_email',
            'facebook.app_id' => 'facebook_app_id',
        ] as $key => $field) {
            $settings->set($key, $data[$field] ?? null);
        }

        if (filled($data['facebook_app_secret'] ?? null)) {
            $settings->set('facebook.app_secret', $data['facebook_app_secret'], secret: true);
        }

        AuditLog::record('settings.updated');

        return back()->with('status', 'Paramètres enregistrés.');
    }
}
