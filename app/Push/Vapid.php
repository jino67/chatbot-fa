<?php

namespace App\Push;

use App\Services\PlatformSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Les clés VAPID de la plateforme : elles prouvent aux services de notification (Google, Apple, Mozilla) que les messages
 * viennent bien de ce serveur. Générées automatiquement à la première utilisation, la clé privée est gardée chiffrée dans
 * les paramètres (jamais affichée, jamais dans un fichier) ; la clé publique est donnée aux navigateurs.
 *
 * Changer de clé oblige chaque appareil à se réabonner : on ne le fait jamais sans raison (`push:keys --renew`).
 */
class Vapid
{
    private const PUBLIC = 'push.vapid_public';

    private const PRIVATE = 'push.vapid_private';

    public function __construct(private readonly PlatformSettings $settings) {}

    public function exists(): bool
    {
        return $this->settings->has(self::PUBLIC) && $this->settings->has(self::PRIVATE);
    }

    /** La clé publique au format que le navigateur attend (point non compressé, base64 sans signes spéciaux). */
    public function publicKey(): string
    {
        $this->ensure();

        return (string) $this->settings->get(self::PUBLIC);
    }

    /** Crée la paire de clés si elle n'existe pas encore (un verrou évite d'en créer deux si deux requêtes arrivent ensemble). */
    public function ensure(): void
    {
        if ($this->exists()) {
            return;
        }

        try {
            Cache::lock('push.vapid.generate', 15)->block(10, function () {
                $this->settings->forget();
                if (! $this->exists()) {
                    $this->generate();
                }
            });
        } catch (LockTimeoutException) {
            // Une autre requête est en train de les créer : on relit ce qu'elle a écrit.
            $this->settings->forget();
            if (! $this->exists()) {
                $this->generate();
            }
        }
    }

    /** Remplace la paire de clés. Tous les appareils devront se réabonner. */
    public function generate(): string
    {
        $pair = WebPushCrypto::generateKeyPair();
        $public = WebPushCrypto::b64urlEncode($pair['public']);

        $this->settings->set(self::PRIVATE, $pair['pem'], secret: true);
        $this->settings->set(self::PUBLIC, $public);

        return $public;
    }

    /** L'en-tête « Authorization » d'un envoi vers ce service de notification. */
    public function authorization(string $endpoint, ?int $expires = null): string
    {
        $this->ensure();

        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $jwt = WebPushCrypto::vapidJwt((string) $this->settings->get(self::PRIVATE), $audience, $this->subject(), $expires);

        return 'vapid t='.$jwt.', k='.$this->publicKey();
    }

    /** Qui contacter en cas de problème : l'adresse e-mail de la marque, sinon l'adresse du site. */
    private function subject(): string
    {
        $brand = $this->settings->brand();
        $email = trim((string) ($brand['email'] ?? ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:'.$email : (string) ($brand['url'] ?: url('/'));
    }
}
