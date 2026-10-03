<?php

namespace App\Social\Auth\Providers;

use App\Social\Auth\SocialIdentity;
use App\Social\Auth\SocialLogin;

/** Un fournisseur de connexion : l'adresse où envoyer la personne, puis l'identité obtenue en échange du code reçu. */
abstract class OAuthProvider
{
    public function __construct(protected readonly SocialLogin $login) {}

    abstract public function key(): string;

    abstract public function label(): string;

    /** Champs à renseigner dans les Paramètres (le reste est calculé). @return list<string> */
    abstract public function fields(): array;

    abstract public function isConfigured(): bool;

    abstract public function authorizationUrl(string $redirectUri, string $state, string $nonce, ?string $challenge): string;

    /**
     * @param  array<string,mixed>  $extra  données qui accompagnent le retour (Apple n'envoie le nom que la première fois)
     *
     * @throws \App\Social\Auth\SocialAuthException
     */
    abstract public function identity(string $code, string $redirectUri, ?string $verifier, string $nonce, array $extra = []): SocialIdentity;

    /** La clé PKCE (S256) protège le code d'autorisation ; Apple et Facebook ne la lisent pas. */
    public function usesPkce(): bool
    {
        return true;
    }

    /** Apple renvoie la personne par un formulaire POST : le retour n'est pas une simple adresse. */
    public function usesFormPost(): bool
    {
        return false;
    }

    protected function credential(string $field): ?string
    {
        return $this->login->credential($this->key(), $field);
    }
}
