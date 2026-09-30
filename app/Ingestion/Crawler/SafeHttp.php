<?php

namespace App\Ingestion\Crawler;

use App\Ingestion\IngestionException;
use Illuminate\Support\Facades\Http;

/**
 * Client HTTP du crawler : chaque saut (redirections comprises) est valide contre le SSRF,
 * l'adresse IP validee est "epinglee" pour la connexion (anti DNS rebinding), et la taille
 * de la reponse est plafonnee pendant la lecture.
 */
final class SafeHttp
{
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    /**
     * @return array{status:int, body:string, type:string, url:string}
     *
     * @throws IngestionException
     */
    public static function get(string $url, int $maxRedirects = 3, ?int $maxBytes = null): array
    {
        $maxBytes ??= (int) config('platform.crawler.max_bytes');

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $target = SafeUrl::assertPublic($url);
            $ip = $target['ips'][0];
            $pinned = $target['host'].':'.$target['port'].':'.(str_contains($ip, ':') ? "[{$ip}]" : $ip);

            $options = ['allow_redirects' => false, 'stream' => true];
            if (defined('CURLOPT_RESOLVE')) {
                $options['curl'] = [CURLOPT_RESOLVE => [$pinned]];
            }

            $response = Http::withUserAgent(config('platform.crawler.user_agent'))
                ->withHeaders(['Accept-Language' => 'fr,en;q=0.8,ar;q=0.5'])
                ->accept('text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.3')
                ->timeout((int) config('platform.crawler.timeout'))
                ->connectTimeout(6)
                ->withOptions($options)
                ->get($url);

            $status = $response->status();

            if (in_array($status, self::REDIRECT_CODES, true) && ($location = $response->header('Location'))) {
                $next = Url::resolve($url, $location);
                if (! $next) {
                    throw new IngestionException("Redirection invalide depuis {$url}");
                }
                $url = $next;

                continue;
            }

            return [
                'status' => $status,
                'body' => self::readCapped($response->toPsrResponse()->getBody(), $maxBytes),
                'type' => $response->header('Content-Type') ?? '',
                'url' => $url,
            ];
        }

        throw new IngestionException("Trop de redirections pour {$url}");
    }

    private static function readCapped($stream, int $max): string
    {
        $data = '';
        while (! $stream->eof() && strlen($data) < $max) {
            $data .= $stream->read(65536);
        }

        return $data;
    }
}
