<?php

namespace App\Social\Auth\Providers;

use App\Social\Auth\SocialAuthException;
use App\Social\Auth\SocialIdentity;
use Illuminate\Support\Facades\Http;

/**
 * Connexion avec Facebook : très répandue chez les petites entreprises. Elle réutilise l'application Meta déjà déclarée pour
 * connecter les pages Facebook (Paramètres, « Facebook »). Facebook n'est pas un fournisseur OpenID Connect : on échange le
 * code contre un jeton, puis on lit le profil. L'adresse n'est jamais considérée comme vérifiée.
 */
final class FacebookProvider extends OAuthProvider
{
    public function key(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    public function fields(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return $this->login->facebookLoginEnabled() && filled($this->credential('app_id')) && filled($this->credential('app_secret'));
    }

    public function usesPkce(): bool
    {
        return false;
    }

    private function version(): string
    {
        return (string) config('platform.facebook.graph_version', 'v23.0');
    }

    public function authorizationUrl(string $redirectUri, string $state, string $nonce, ?string $challenge): string
    {
        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->credential('app_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'email,public_profile',
            'response_type' => 'code',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function identity(string $code, string $redirectUri, ?string $verifier, string $nonce, array $extra = []): SocialIdentity
    {
        $secret = (string) $this->credential('app_secret');

        try {
            $token = Http::acceptJson()->timeout(20)->get('https://graph.facebook.com/'.$this->version().'/oauth/access_token', [
                'client_id' => $this->credential('app_id'), 'client_secret' => $secret, 'redirect_uri' => $redirectUri, 'code' => $code,
            ]);
            $accessToken = $token->successful() ? (string) $token->json('access_token') : '';

            $profile = $accessToken === '' ? null : Http::acceptJson()->timeout(20)->get('https://graph.facebook.com/'.$this->version().'/me', [
                'fields' => 'id,name,email,picture.type(large)',
                'access_token' => $accessToken,
                'appsecret_proof' => hash_hmac('sha256', $accessToken, $secret),
            ]);
        } catch (\Throwable $e) {
            report($e);

            throw new SocialAuthException('Impossible de joindre Facebook pour le moment. Réessayez dans un instant.');
        }

        if (! $profile || ! $profile->successful() || ! is_string($profile->json('id'))) {
            report(new \RuntimeException('Connexion Facebook refusée : HTTP '.($profile?->status() ?? $token->status())));

            throw new SocialAuthException('La connexion avec Facebook a été refusée. Réessayez, ou utilisez une autre méthode.');
        }

        $email = $profile->json('email');
        $avatar = $profile->json('picture.data.url');

        return new SocialIdentity('facebook', (string) $profile->json('id'), is_string($email) && $email !== '' ? mb_strtolower($email) : null, false, $profile->json('name'), is_string($avatar) ? $avatar : null);
    }
}
