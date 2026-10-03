<?php

namespace App\Social\Auth;

/**
 * Ce qu'un fournisseur (Google, Apple, Microsoft, Facebook) dit de la personne qui se connecte. `emailVerified` n'est vrai
 * que si le fournisseur l'affirme lui-même : c'est lui qui autorise (ou non) à rattacher la personne à un compte existant.
 */
final class SocialIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly ?string $avatar = null,
        public readonly bool $relayEmail = false,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['provider'], (string) $data['id'], $data['email'] ?? null, (bool) ($data['emailVerified'] ?? false),
            $data['name'] ?? null, $data['avatar'] ?? null, (bool) ($data['relayEmail'] ?? false),
        );
    }
}
