<?php

namespace App\Social\Auth\Providers;

use App\Social\Auth\SocialIdentity;

final class GoogleProvider extends OidcProvider
{
    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google';
    }

    public function fields(): array
    {
        return ['client_id', 'client_secret'];
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function scopes(): string
    {
        return 'openid email profile';
    }

    protected function extraAuthorizeParams(): array
    {
        // Laisse choisir le compte : un téléphone connecté à plusieurs comptes Google ne prend pas le mauvais sans le dire.
        return ['prompt' => 'select_account'];
    }

    protected function issuerIsValid(string $iss, array $claims): bool
    {
        return in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true);
    }

    protected function fromClaims(array $claims, array $extra): SocialIdentity
    {
        return new SocialIdentity(
            'google', (string) $claims['sub'], isset($claims['email']) ? mb_strtolower((string) $claims['email']) : null,
            self::truthy($claims['email_verified'] ?? false), $claims['name'] ?? null, $claims['picture'] ?? null,
        );
    }
}
