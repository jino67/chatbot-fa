<?php

namespace App\Social\Auth;

use App\Push\WebPushCrypto;
use RuntimeException;

/**
 * Lecture et écriture des jetons JWT de la connexion externe.
 *
 * La lecture ne vérifie PAS la signature, volontairement : le jeton d'identité n'est jamais pris dans l'adresse de retour (que la
 * personne peut modifier) mais reçu directement du fournisseur, en échange du code, par une requête HTTPS du serveur. La norme
 * OpenID Connect (3.1.3.7) autorise alors à se fier à la connexion TLS plutôt qu'à la signature. Les revendications (émetteur,
 * destinataire, expiration, nonce) sont vérifiées par chaque fournisseur.
 */
final class Jwt
{
    /** @return array<string,mixed> */
    public static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SocialAuthException('La réponse du fournisseur de connexion est illisible.');
        }

        $claims = json_decode(WebPushCrypto::b64urlDecode($parts[1]), true);

        return is_array($claims) ? $claims : throw new SocialAuthException('La réponse du fournisseur de connexion est illisible.');
    }

    /**
     * Un JWT signé ES256 (le secret client d'Apple est un jeton signé avec la clé privée .p8 du compte développeur).
     *
     * @param  array<string,mixed>  $header
     * @param  array<string,mixed>  $payload
     */
    public static function signEs256(array $header, array $payload, string $privatePem): string
    {
        $head = WebPushCrypto::b64urlEncode(json_encode(['alg' => 'ES256', 'typ' => 'JWT'] + $header));
        $body = WebPushCrypto::b64urlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($privatePem);
        if (! $key || ! openssl_sign($head.'.'.$body, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature impossible : la clé privée est invalide.');
        }

        return $head.'.'.$body.'.'.WebPushCrypto::b64urlEncode(WebPushCrypto::derToRaw($der));
    }
}
