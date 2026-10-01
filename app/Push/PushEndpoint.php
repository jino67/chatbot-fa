<?php

namespace App\Push;

/**
 * L'adresse d'un abonnement vient du navigateur de la personne : on ne contacte que les vrais services de notification
 * (liste dans config/notifications.php), en HTTPS, sur leur domaine. Sans cela, quelqu'un pourrait « s'abonner » avec
 * l'adresse d'un serveur interne et faire envoyer des requêtes depuis notre serveur.
 */
final class PushEndpoint
{
    public static function isAllowed(string $endpoint): bool
    {
        if (strlen($endpoint) > 1000) {
            return false;
        }

        $parts = parse_url($endpoint);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }

        foreach ((array) config('notifications.push.hosts', []) as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /** Le type d'appareil, déduit de l'agent du navigateur : de quoi afficher « Téléphone Android » dans la liste. */
    public static function platform(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'ios',
            (bool) preg_match('/Android/i', $ua) => 'android',
            $ua === '' => 'other',
            default => 'desktop',
        };
    }
}
