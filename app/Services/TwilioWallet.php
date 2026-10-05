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

    /**
     * Twilio ne donne le solde que d'un compte principal : un sous-compte (celui qu'on crée pour essayer WhatsApp) répond
     * « resource not found ». On peut alors renseigner, rien que pour lire le solde, les identifiants du compte principal.
     *
     * @return array{ok:bool, configured:bool, balance:?float, currency:?string, error:?string, checked_at:?string}
     */
    private function fetch(): array
    {
        $sid = (string) $this->settings->get('whatsapp.twilio.account_sid');
        $token = (string) $this->settings->get('whatsapp.twilio.auth_token');
        // Identifiants du compte principal, facultatifs : ils ne servent qu'à lire le solde.
        $balanceSid = (string) ($this->settings->get('whatsapp.twilio.balance_sid') ?: $sid);
        $balanceToken = $this->settings->get('whatsapp.twilio.balance_sid') ? (string) $this->settings->get('whatsapp.twilio.balance_token') : $token;
        $fail = fn (string $error) => ['ok' => false, 'configured' => true, 'balance' => null, 'currency' => null, 'error' => $error, 'checked_at' => now()->toIso8601String()];

        try {
            $response = Http::withBasicAuth($balanceSid, $balanceToken)->timeout(10)->acceptJson()
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$balanceSid}/Balance.json");

            if ($response->status() === 404) {
                $account = Http::withBasicAuth($balanceSid, $balanceToken)->timeout(10)->acceptJson()->get("https://api.twilio.com/2010-04-01/Accounts/{$balanceSid}.json");
                $owner = $account->successful() ? (string) $account->json('owner_account_sid') : '';

                return $fail($owner !== '' && $owner !== $balanceSid
                    ? 'Ce compte Twilio est un sous-compte : Twilio ne donne le solde que du compte principal. Renseignez ci-dessous, dans les Paramètres, le SID et le jeton du compte principal (seulement pour lire le solde), ou consultez le solde dans la console Twilio.'
                    : 'Twilio ne trouve pas ce compte pour lire le solde : vérifiez le SID, ou utilisez les identifiants réels (pas ceux du mode test).');
            }
        } catch (\Throwable $e) {
            return $fail('Twilio injoignable : '.$e->getMessage());
        }

        if (! $response->successful()) {
            return $fail('Twilio HTTP '.$response->status().' : '.$response->json('message', 'identifiants refusés'));
        }

        return [
            'ok' => true, 'configured' => true, 'balance' => (float) $response->json('balance'),
            'currency' => (string) $response->json('currency', 'USD'), 'error' => null, 'checked_at' => now()->toIso8601String(),
        ];
    }
}
