<?php

namespace App\Social;

/** Reconnait une adresse Facebook ou Instagram collee par un client (jamais chargee par notre serveur). */
final class FacebookUrl
{
    /**
     * @return array{platform:string, kind:string, slug:?string, url:string}|null null si ce n'est pas un reseau social
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^(www|m|web|mbasic|l)\./', '', $host);

        $platform = match (true) {
            $host === 'facebook.com', $host === 'fb.com', $host === 'fb.me' => 'facebook',
            $host === 'instagram.com' => 'instagram',
            default => null,
        };

        if (! $platform) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        parse_str((string) ($parts['query'] ?? ''), $query);

        // Instagram place le type de contenu en premier segment (/p/CODE, /reel/CODE), Facebook apres le nom de la page.
        $instagramPost = $platform === 'instagram' && in_array($segments[0] ?? '', ['p', 'reel', 'reels', 'tv'], true);

        $kind = match (true) {
            ($segments[0] ?? '') === 'profile.php' => 'profile',
            ($segments[0] ?? '') === 'groups' => 'group',
            $instagramPost, in_array($segments[1] ?? '', ['posts', 'photos', 'videos', 'reel', 'p'], true) => 'post',
            $platform === 'instagram' => 'profile',
            $segments !== [] => 'page',
            default => 'unknown',
        };

        $slug = match (true) {
            $kind === 'profile' && isset($query['id']) => (string) $query['id'],
            $instagramPost => null, // « p » ou « reel » n'est pas un nom de compte
            default => $segments[0] ?? null,
        };

        return ['platform' => $platform, 'kind' => $kind, 'slug' => $slug, 'url' => $url];
    }
}
