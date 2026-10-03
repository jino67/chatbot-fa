<?php

namespace App\Social\Auth;

use App\Services\PlatformSettings;
use App\Social\Auth\Providers\AppleProvider;
use App\Social\Auth\Providers\FacebookProvider;
use App\Social\Auth\Providers\GoogleProvider;
use App\Social\Auth\Providers\MicrosoftProvider;
use App\Social\Auth\Providers\OAuthProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Le point d'entrée de la connexion externe : quels fournisseurs sont réglés, où envoyer la personne, et comment reconnaître
 * son retour. Chaque départ garde côté serveur un état à usage unique (valable dix minutes) et dépose dans le navigateur un
 * cookie qui le lie : un lien de retour reçu par un autre navigateur, ou rejoué, est refusé.
 */
final class SocialLogin
{
    public const COOKIE = 'kouma_oauth';

    /** @var array<string,class-string<OAuthProvider>> */
    public const PROVIDERS = [
        'google' => GoogleProvider::class,
        'apple' => AppleProvider::class,
        'microsoft' => MicrosoftProvider::class,
        'facebook' => FacebookProvider::class,
    ];

    public function __construct(private readonly PlatformSettings $settings) {}

    public function provider(string $key): ?OAuthProvider
    {
        $class = self::PROVIDERS[$key] ?? null;

        return $class ? new $class($this) : null;
    }

    /** @return array<string,OAuthProvider> les fournisseurs réglés, dans l'ordre d'affichage */
    public function enabled(): array
    {
        $out = [];
        foreach (array_keys(self::PROVIDERS) as $key) {
            $provider = $this->provider($key);
            if ($provider?->isConfigured()) {
                $out[$key] = $provider;
            }
        }

        return $out;
    }

    /** Tous les fournisseurs, réglés ou non (page des Paramètres). @return array<string,OAuthProvider> */
    public function all(): array
    {
        return array_map(fn (string $class) => new $class($this), self::PROVIDERS);
    }

    public function credential(string $provider, string $field): ?string
    {
        $value = match (true) {
            // Facebook : l'application Meta des pages.
            $provider === 'facebook' => $this->settings->get('facebook.'.$field) ?: config('platform.facebook.'.$field),
            default => $this->settings->get("social.{$provider}.{$field}") ?: config("social.{$provider}.{$field}"),
        };

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Facebook demande en plus un feu vert explicite : l'application Meta doit être passée en production avant. */
    public function facebookLoginEnabled(): bool
    {
        return (bool) $this->settings->get('social.facebook.login', false);
    }

    public function redirectUri(string $provider): string
    {
        return route('social.callback', $provider);
    }

    /** Envoie la personne chez le fournisseur. @param 'login'|'link' $intent */
    public function begin(Request $request, OAuthProvider $provider, string $intent, ?int $userId = null): RedirectResponse
    {
        $state = Str::random(40);
        $nonce = Str::random(32);
        $verifier = Str::random(80);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $bind = Str::random(32);

        Cache::put('social:state:'.hash('sha256', $state), [
            'provider' => $provider->key(), 'nonce' => $nonce, 'verifier' => $verifier, 'intent' => $intent, 'user_id' => $userId, 'bind' => $bind,
        ], 600);

        // Apple revient par un POST venu d'un autre site : le cookie doit alors être « SameSite=None » (donc en HTTPS).
        $secure = $request->isSecure();
        $cookie = cookie(self::COOKIE, $bind, 10, '/', null, $secure, true, false, $secure ? 'none' : 'lax');

        return redirect()->away($provider->authorizationUrl($this->redirectUri($provider->key()), $state, $nonce, $challenge))->withCookie($cookie);
    }

    /**
     * Reconnaît le retour du fournisseur : l'état existe, n'a pas déjà servi, correspond à ce fournisseur et à ce navigateur.
     *
     * @return array{provider:string,nonce:string,verifier:string,intent:string,user_id:?int,bind:string}
     *
     * @throws SocialAuthException
     */
    public function consume(Request $request, string $provider): array
    {
        $state = (string) $request->input('state');
        $data = $state === '' ? null : Cache::pull('social:state:'.hash('sha256', $state));

        if (! is_array($data) || $data['provider'] !== $provider || ! hash_equals((string) $data['bind'], (string) $request->cookie(self::COOKIE))) {
            throw new SocialAuthException('Cette connexion a expiré ou vient d\'un autre navigateur. Recommencez depuis cette page.');
        }

        return $data;
    }
}
