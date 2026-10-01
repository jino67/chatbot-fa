<?php

namespace App\Push;

use RuntimeException;

/**
 * Chiffrement d'un message Web Push (RFC 8291, « aes128gcm ») et signature VAPID (RFC 8292), en PHP pur avec OpenSSL.
 * Aucune bibliothèque de plus : sur un hébergement sans accès SSH, ajouter un paquet à `vendor/` serait un envoi de
 * milliers de fichiers. Vérifié contre l'exemple publié dans l'annexe A de la RFC 8291 (voir PushCryptoTest).
 */
final class WebPushCrypto
{
    /** Préfixe DER d'une clé publique P-256 (SubjectPublicKeyInfo) : à faire suivre du point non compressé de 65 octets. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** Taille de bloc annoncée dans l'en-tête du message : un message de notification tient toujours dans un seul bloc. */
    private const RECORD_SIZE = 4096;

    /** Taille maximale du texte (4096 moins l'octet de fin, le sel, la clé et l'étiquette). */
    public const MAX_PAYLOAD = 3993;

    public static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Une paire de clés P-256 neuve.
     *
     * @return array{pem:string, public:string} le PEM privé, et le point public non compressé (65 octets, binaire)
     */
    public static function generateKeyPair(): array
    {
        $key = self::newKey();
        openssl_pkey_export($key, $pem, null, self::config());

        return ['pem' => $pem, 'public' => self::publicPoint($key)];
    }

    /**
     * Une clé P-256 neuve. Sous Windows, PHP n'a souvent pas de fichier openssl.cnf : on se rabat alors sur celui du dépôt
     * (resources/openssl), qui ne change aucun réglage de sécurité.
     */
    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = openssl_pkey_new($options + self::config());

        if (! $key) {
            while (openssl_error_string()) {
                // On vide la file d'erreurs avant de réessayer avec la configuration du dépôt.
            }
            $key = openssl_pkey_new($options + ['config' => self::fallbackConfig()]);
        }

        if (! $key) {
            throw new RuntimeException('OpenSSL ne sait pas créer de clé P-256 : '.openssl_error_string());
        }

        return $key;
    }

    /** @return array<string,string> */
    private static function config(): array
    {
        static $needed = null;

        if ($needed === null) {
            $probe = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            while (openssl_error_string()) {
                // File d'erreurs vidée : le test ci-dessus ne doit pas laisser de trace.
            }
            $needed = $probe === false;
        }

        return $needed ? ['config' => self::fallbackConfig()] : [];
    }

    private static function fallbackConfig(): string
    {
        return dirname(__DIR__, 2).'/resources/openssl/openssl.cnf';
    }

    /** Le point public (0x04 || x || y) d'une clé OpenSSL. */
    public static function publicPoint(\OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'] ?? null;
        if (! $ec) {
            throw new RuntimeException('Clé non elliptique.');
        }

        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** Une clé privée OpenSSL à partir de ses éléments bruts (utile pour rejouer l'exemple de la RFC). */
    public static function privateKeyFromRaw(string $d, string $publicPoint): \OpenSSLAsymmetricKey
    {
        $der = hex2bin('30770201010420').str_pad($d, 32, "\0", STR_PAD_LEFT).hex2bin('a00a06082a8648ce3d030107a144034200').$publicPoint;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";

        $key = openssl_pkey_get_private($pem);
        if (! $key) {
            throw new RuntimeException('Clé privée illisible : '.openssl_error_string());
        }

        return $key;
    }

    /** Une clé publique OpenSSL à partir d'un point non compressé de 65 octets. */
    public static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('Clé publique du navigateur invalide.');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (! $key) {
            throw new RuntimeException('Clé publique du navigateur illisible : '.openssl_error_string());
        }

        return $key;
    }

    /**
     * Chiffre un message pour un abonnement (RFC 8291) et renvoie le corps à envoyer au service de notification.
     *
     * @param  string  $uaPublic  clé publique de l'abonnement (p256dh), 65 octets
     * @param  string  $authSecret  secret d'authentification de l'abonnement (auth), 16 octets
     * @param  \OpenSSLAsymmetricKey|null  $ephemeral  clé éphémère imposée (tests seulement)
     * @param  string|null  $salt  sel imposé (tests seulement)
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?\OpenSSLAsymmetricKey $ephemeral = null, ?string $salt = null): string
    {
        if (strlen($payload) > self::MAX_PAYLOAD) {
            throw new RuntimeException('Message trop long pour une notification.');
        }
        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('Secret d\'authentification invalide.');
        }

        $ephemeral ??= self::newKey();
        $asPublic = self::publicPoint($ephemeral);
        $salt ??= random_bytes(16);

        $secret = openssl_pkey_derive(self::publicKeyFromPoint($uaPublic), $ephemeral, 32);
        if ($secret === false) {
            throw new RuntimeException('Échange de clés impossible : '.openssl_error_string());
        }

        // RFC 8291, 3.4 : la clé de contenu se déduit du secret partagé, du secret d'authentification et des deux clés publiques.
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Un seul bloc : le texte, puis l'octet 0x02 qui marque le dernier bloc.
        $tag = '';
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Chiffrement impossible : '.openssl_error_string());
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$asPublic.$cipher.$tag;
    }

    /**
     * Le jeton VAPID (JWT signé ES256) qui prouve au service de notification que le message vient bien de ce serveur.
     *
     * @param  string  $privatePem  clé privée VAPID
     * @param  string  $audience  origine du service de notification (https://fcm.googleapis.com)
     * @param  string  $subject  « mailto:adresse » ou adresse du site
     */
    public static function vapidJwt(string $privatePem, string $audience, string $subject, ?int $expires = null): string
    {
        $head = self::b64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $body = self::b64urlEncode(json_encode(['aud' => $audience, 'exp' => $expires ?? time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($privatePem);
        if (! $key || ! openssl_sign($head.'.'.$body, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature VAPID impossible : '.openssl_error_string());
        }

        return $head.'.'.$body.'.'.self::b64urlEncode(self::derToRaw($der));
    }

    /** Une signature ECDSA codée en DER devient les 64 octets R || S que demande le format JWT. */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);
        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($integer, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}
