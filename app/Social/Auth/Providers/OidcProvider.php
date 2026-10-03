<?php

namespace App\Social\Auth\Providers;

use App\Social\Auth\Jwt;
use App\Social\Auth\SocialAuthException;
use App\Social\Auth\SocialIdentity;
use Illuminate\Support\Facades\Http;

/**
 * OpenID Connect avec le flux « code d'autorisation » : Google, Microsoft et Apple. Le jeton d'identité est reçu du
 * fournisseur en échange du code (voir Jwt pour pourquoi sa signature n'est pas relue), puis ses revendications sont
 * contrôlées : destinataire (notre client), émetteur, expiration et nonce (propre à cette connexion).
 */
abstract class OidcProvider extends OAuthProvider
{
    abstract protected function authorizeEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    abstract protected function scopes(): string;

    /** @param array<string,mixed> $claims */
    abstract protected function issuerIsValid(string $iss, array $claims): bool;

    /**
     * @param  array<string,mixed>  $claims
     * @param  array<string,mixed>  $extra
     */
    abstract protected function fromClaims(array $claims, array $extra): SocialIdentity;

    protected function clientId(): ?string
    {
        return $this->credential('client_id');
    }

    protected function clientSecret(): ?string
    {
        return $this->credential('client_secret');
    }

    /** @return array<string,string> */
    protected function extraAuthorizeParams(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    public function authorizationUrl(string $redirectUri, string $state, string $nonce, ?string $challenge): string
    {
        $params = [
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scopes(),
            'state' => $state,
            'nonce' => $nonce,
        ];

        if ($challenge && $this->usesPkce()) {
            $params += ['code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
        }

        return $this->authorizeEndpoint().'?'.http_build_query($params + $this->extraAuthorizeParams(), '', '&', PHP_QUERY_RFC3986);
    }

    public function identity(string $code, string $redirectUri, ?string $verifier, string $nonce, array $extra = []): SocialIdentity
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ];

        if ($verifier && $this->usesPkce()) {
            $body['code_verifier'] = $verifier;
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(20)->post($this->tokenEndpoint(), $body);
        } catch (\Throwable $e) {
            report($e);

            throw new SocialAuthException('Impossible de joindre '.$this->label().' pour le moment. Réessayez dans un instant.');
        }

        $idToken = $response->successful() ? $response->json('id_token') : null;
        if (! is_string($idToken) || $idToken === '') {
            // Le détail (invalid_grant, invalid_client...) va au journal technique, jamais à l'écran.
            report(new \RuntimeException('Connexion '.$this->key().' refusée : HTTP '.$response->status().' '.($response->json('error') ?? '')));

            throw new SocialAuthException('La connexion avec '.$this->label().' a été refusée. Réessayez, ou utilisez une autre méthode.');
        }

        $claims = Jwt::claims($idToken);
        $this->validate($claims, $nonce);

        return $this->fromClaims($claims, $extra);
    }

    /** @param array<string,mixed> $claims */
    private function validate(array $claims, string $nonce): void
    {
        $audience = (array) ($claims['aud'] ?? []);
        $valid = in_array($this->clientId(), $audience, true)
            && is_string($claims['iss'] ?? null) && $this->issuerIsValid($claims['iss'], $claims)
            && (int) ($claims['exp'] ?? 0) >= time() - 60
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== ''
            && hash_equals($nonce, (string) ($claims['nonce'] ?? ''));

        if (! $valid) {
            throw new SocialAuthException('La réponse de '.$this->label().' n\'a pas pu être vérifiée. Réessayez.');
        }
    }

    /** « true » en texte ou en booléen : les fournisseurs ne s'accordent pas. */
    protected static function truthy(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }
}
