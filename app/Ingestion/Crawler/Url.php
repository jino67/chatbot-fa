<?php

namespace App\Ingestion\Crawler;

/** Normalisation et resolution d'URL pour le crawler. */
final class Url
{
    public static function normalize(string $url): string
    {
        $url = trim(strtok($url, '#') ?: $url);
        $p = parse_url($url);
        if (! $p || empty($p['host'])) {
            return $url;
        }

        $scheme = strtolower($p['scheme'] ?? 'https');
        $host = strtolower($p['host']);
        $port = isset($p['port']) && ! (($scheme === 'https' && $p['port'] === 443) || ($scheme === 'http' && $p['port'] === 80))
            ? ':'.$p['port'] : '';
        $path = $p['path'] ?? '/';
        $path = $path === '' ? '/' : (strlen($path) > 1 ? rtrim($path, '/') : $path);

        $query = '';
        if (! empty($p['query'])) {
            parse_str($p['query'], $params);
            foreach (array_keys($params) as $key) {
                if (preg_match('/^(utm_|fbclid|gclid|mc_|ref$|_ga)/i', (string) $key)) {
                    unset($params[$key]);
                }
            }
            $query = $params ? '?'.http_build_query($params) : '';
        }

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    public static function resolve(string $base, string $relative): ?string
    {
        $relative = trim($relative);
        $b = parse_url($base);
        if ($relative === '' || ! $b || empty($b['host'])) {
            return null;
        }

        if (preg_match('#^https?://#i', $relative)) {
            return self::normalize($relative);
        }

        $origin = ($b['scheme'] ?? 'https').'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($relative, '//')) {
            return self::normalize(($b['scheme'] ?? 'https').':'.$relative);
        }

        $relative = strtok($relative, '#') ?: '';
        if (str_starts_with($relative, '/')) {
            $path = $relative;
        } elseif (str_starts_with($relative, '?')) {
            $path = ($b['path'] ?? '/').$relative;
        } else {
            $path = preg_replace('#[^/]*$#', '', $b['path'] ?? '/').$relative;
        }

        [$pathOnly, $query] = array_pad(explode('?', $path, 2), 2, null);
        $segments = [];
        foreach (explode('/', $pathOnly) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return self::normalize($origin.'/'.implode('/', $segments).($query !== null ? '?'.$query : ''));
    }

    /** Meme site : memes hotes, a "www." pres. */
    public static function sameSite(string $a, string $b): bool
    {
        return self::host($a) === self::host($b);
    }

    public static function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public static function looksLikeBinary(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return (bool) preg_match('/\.(pdf|jpe?g|png|gif|webp|svg|ico|zip|rar|7z|gz|mp3|mp4|avi|mov|wmv|docx?|xlsx?|pptx?|css|js|json|xml|woff2?|ttf|eot|apk|exe|dmg)$/i', $path);
    }
}
