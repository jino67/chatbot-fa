<?php

namespace App\Social\Auth\Providers;

use App\Social\Auth\Jwt;
use App\Social\Auth\SocialIdentity;

/**
 * « Se connecter avec Apple ». Particularités : le secret client est un jeton signé avec la clé privée .p8 du compte
 * développeur ; Apple renvoie la personne par un formulaire POST (obligatoire dès qu'on demande le nom et l'e-mail) ;
 * le nom n'est donné que la toute première fois ; l'adresse peut être un relais privé (@privaterelay.appleid.com).
 */
final class AppleProvider extends OidcProvider
{
    public function key(): string
    {
        return 'apple';
    }

    public function label(): string
    {
        return 'Apple';
    }

    public function fields(): array
    {
        return ['client_id', 'team_id', 'key_id', 'private_key'];
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId()) && filled($this->credential('team_id')) && filled($this->credential('key_id')) && filled($this->credential('private_key'));
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function usesFormPost(): bool
    {
        return true;
    }

    protected function authorizeEndpoint(): string
    {
        return 'https://appleid.apple.com/auth/authorize';
    }

    protected function tokenEndpoint(): string
    {
        return 'https://appleid.apple.com/auth/token';
    }

    protected function scopes(): string
    {
        return 'name email';
    }

    protected function extraAuthorizeParams(): array
    {
        return ['response_mode' => 'form_post'];
    }

    /** Le secret client : un jeton valable quelques minutes, signé avec la clé du compte développeur. */
    protected function clientSecret(): ?string
    {
        $pem = (string) $this->credential('private_key');
        if ($pem === '') {
            return null;
        }

        // Une clé collée sans retours à la ligne (« -----BEGIN PRIVATE KEY----- MIGT... ») est remise en forme.
        if (! str_contains($pem, "\n")) {
            $pem = preg_replace('/\s+/', "\n", trim($pem));
            $pem = preg_replace('/-----BEGIN\nPRIVATE\nKEY-----/', '-----BEGIN PRIVATE KEY-----', $pem);
            $pem = preg_replace('/-----END\nPRIVATE\nKEY-----/', '-----END PRIVATE KEY-----', $pem);
        }

        return Jwt::signEs256(
            ['kid' => (string) $this->credential('key_id')],
            ['iss' => (string) $this->credential('team_id'), 'iat' => time(), 'exp' => time() + 300, 'aud' => 'https://appleid.apple.com', 'sub' => (string) $this->clientId()],
            $pem,
        );
    }

    protected function issuerIsValid(string $iss, array $claims): bool
    {
        return $iss === 'https://appleid.apple.com';
    }

    protected function fromClaims(array $claims, array $extra): SocialIdentity
    {
        // Le nom arrive dans le formulaire de retour, au format JSON : {"name":{"firstName":"Awa","lastName":"Ouedraogo"}}.
        $user = is_string($extra['user'] ?? null) ? json_decode($extra['user'], true) : (array) ($extra['user'] ?? []);
        $name = trim(($user['name']['firstName'] ?? '').' '.($user['name']['lastName'] ?? ''));
        $email = isset($claims['email']) ? mb_strtolower((string) $claims['email']) : null;

        return new SocialIdentity(
            'apple', (string) $claims['sub'], $email, self::truthy($claims['email_verified'] ?? false),
            $name !== '' ? $name : null, null, self::truthy($claims['is_private_email'] ?? false),
        );
    }
}
