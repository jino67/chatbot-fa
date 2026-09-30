<?php

namespace App\Ingestion\Crawler;

use Closure;

/**
 * Protection SSRF : le crawler ne doit jamais pouvoir atteindre le reseau interne
 * (127.0.0.1, 10.x, 192.168.x, metadonnees cloud 169.254.169.254, ...).
 * Chaque URL, et chaque redirection, passe par assertPublic().
 */
final class SafeUrl
{
    private static ?Closure $resolver = null;

    /** Permet aux tests de simuler la resolution DNS. */
    public static function useResolver(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @return array{host:string, port:int, scheme:string, ips:list<string>}
     *
     * @throws UnsafeUrlException
     */
    public static function assertPublic(string $url): array
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['host']) || empty($parts['scheme'])) {
            throw new UnsafeUrlException("URL invalide : {$url}");
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new UnsafeUrlException('Seuls les liens http et https sont acceptés.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('Les liens contenant un identifiant ou un mot de passe sont refusés.');
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new UnsafeUrlException("Port non autorisé : {$port}.");
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            throw new UnsafeUrlException("Adresse interne refusée : {$host}");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException("Le site {$host} est introuvable (nom de domaine inconnu).");
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new UnsafeUrlException("Adresse interne refusée : {$host} pointe vers une adresse privée.");
            }
        }

        return ['host' => $host, 'port' => $port, 'scheme' => $scheme, 'ips' => $ips];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }

        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }
}
