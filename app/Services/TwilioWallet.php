<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Solde du compte Twilio de la plateforme (le portefeuille qui paie les messages WhatsApp de tous les clients).
 * Lu en direct aupres de Twilio, avec un cache de 45 secondes pour ne pas l'interroger a chaque rafraichissement.
 */
class TwilioWallet
{
    private const CACHE_KEY = 'wallet.twilio.balance';

    public function __construct(private readonly PlatformSettings $settings) {}

    public function configured(): bool
    {
        return $this->settings->has('whatsapp.twilio.account_sid') && $this->settings->has('whatsapp.twilio.auth_token');
    }

    /** @return array{ok:bool, configured:bool, balance:?float, currency:?string, error:?string, checked_at:?string} */
    public function balance(bool $fresh = false): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'configured' => false, 'balance' => null, 'currency' => null, 'error' => 'Compte Twilio de la plateforme non renseigné.', 'checked_at' => null];
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, 45, fn () => $this->fetch());
    }

    /** @return array{ok:bool, configured:bool, balance:?float, currency:?string, error:?string, checked_at:?string} */
    private function fetch(): array
    {
        $sid = (string) $this->settings->get('whatsapp.twilio.account_sid');

        try {
            $response = Http::withBasicAuth($sid, (string) $this->settings->get('whatsapp.twilio.auth_token'))
                ->timeout(10)->acceptJson()
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Balance.json");
        } catch (\Throwable $e) {
            return ['ok' => false, 'configured' => true, 'balance' => null, 'currency' => null, 'error' => 'Twilio injoignable : '.$e->getMessage(), 'checked_at' => now()->toIso8601String()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'configured' => true, 'balance' => null, 'currency' => null, 'error' => 'Twilio HTTP '.$response->status().' : '.$response->json('message', 'identifiants refusés'), 'checked_at' => now()->toIso8601String()];
        }

        return [
            'ok' => true, 'configured' => true, 'balance' => (float) $response->json('balance'),
            'currency' => (string) $response->json('currency', 'USD'), 'error' => null, 'checked_at' => now()->toIso8601String(),
        ];
    }
}
