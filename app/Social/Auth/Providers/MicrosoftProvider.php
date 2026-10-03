<?php

namespace App\Social\Auth\Providers;

use App\Social\Auth\SocialIdentity;

/** Comptes Microsoft personnels (Outlook, Hotmail) et professionnels (Microsoft 365) : locataire « common » par défaut. */
final class MicrosoftProvider extends OidcProvider
{
    public function key(): string
    {
        return 'microsoft';
    }

    public function label(): string
    {
        return 'Microsoft';
    }

    public function fields(): array
    {
        return ['client_id', 'client_secret', 'tenant'];
    }

    private function tenant(): string
    {
        $tenant = trim((string) $this->credential('tenant'));

        return preg_match('/^[A-Za-z0-9.-]+$/', $tenant) ? $tenant : 'common';
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://login.microsoftonline.com/'.$this->tenant().'/oauth2/v2.0/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://login.microsoftonline.com/'.$this->tenant().'/oauth2/v2.0/token';
    }

    protected function scopes(): string
    {
        return 'openid email profile';
    }

    protected function extraAuthorizeParams(): array
    {
        return ['prompt' => 'select_account', 'response_mode' => 'query'];
    }

    protected function issuerIsValid(string $iss, array $claims): bool
    {
        // Le locataire est dans l'émetteur : il doit être celui du jeton, et, si on a configuré un identifiant précis, celui-là.
        $tid = (string) ($claims['tid'] ?? '');
        $guid = (bool) preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $this->tenant());

        return $tid !== '' && $iss === 'https://login.microsoftonline.com/'.$tid.'/v2.0' && (! $guid || strcasecmp($tid, $this->tenant()) === 0);
    }

    protected function fromClaims(array $claims, array $extra): SocialIdentity
    {
        $email = $claims['email'] ?? (str_contains((string) ($claims['preferred_username'] ?? ''), '@') ? $claims['preferred_username'] : null);

        // Microsoft ne garantit pas que l'adresse a été vérifiée : elle ne sert jamais à rattacher un compte existant.
        return new SocialIdentity('microsoft', (string) $claims['sub'], $email ? mb_strtolower((string) $email) : null, false, $claims['name'] ?? null);
    }
}
