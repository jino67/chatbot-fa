<?php

namespace App\Ingestion\Crawler;

/**
 * Les adresses d'un plan de site (sitemap.xml). Un site un peu grand publie un index qui renvoie vers plusieurs plans
 * (produits, pages, catégories, articles) : on les lit tous, dans l'ordre utile à un client (pages et catégories avant
 * produits, articles de blog en dernier), pour que les produits très nombreux ne cachent pas les pages d'information.
 */
final class Sitemap
{
    /** Nombre de plans lus au plus (l'index compte pour un). */
    private const MAX_FILES = 14;

    /** @return list<string> */
    public static function urls(string $start, RobotsTxt $robots, int $limit): array
    {
        $p = parse_url($start);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');

        $queue = array_values(array_unique([...$robots->sitemaps(), $origin.'/sitemap.xml']));
        // Certains sites ne publient que « sitemap_index.xml » : essayé seulement quand rien n'a été trouvé.
        $fallback = $origin.'/sitemap_index.xml';
        $urls = [];
        $fetched = 0;
        $seen = [];

        while ($fetched < self::MAX_FILES && count($urls) < $limit) {
            if ($queue === []) {
                if ($urls !== [] || $fallback === null) {
                    break;
                }
                $queue = [$fallback];
                $fallback = null;
            }
            $sitemap = array_shift($queue);
            if (isset($seen[$sitemap]) || ! Url::sameSite($start, $sitemap)) {
                continue;
            }
            $seen[$sitemap] = true;
            $fetched++;

            try {
                $res = SafeHttp::get($sitemap, 2, 3_000_000);
            } catch (\Throwable) {
                continue;
            }
            if ($res['status'] !== 200 || ! preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $res['body'], $m)) {
                continue;
            }

            if (stripos($res['body'], '<sitemapindex') !== false) {
                $children = array_map('html_entity_decode', $m[1]);
                usort($children, fn ($a, $b) => self::rank($a) <=> self::rank($b));
                // Les plans enfants passent devant les plans restants de la file : l'index a été lu pour eux.
                array_unshift($queue, ...$children);

                continue;
            }

            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc);
                if (Url::sameSite($start, $loc) && ! Url::looksLikeBinary($loc)) {
                    $urls[] = Url::normalize($loc);
                }
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, $limit);
    }

    /** Plus petit = lu plus tôt : pages et catégories, puis le reste, puis produits, puis articles. */
    private static function rank(string $sitemapUrl): int
    {
        $name = strtolower(basename((string) parse_url($sitemapUrl, PHP_URL_PATH)));

        return match (true) {
            (bool) preg_match('/page|categor|collection|gamme|menu|service/', $name) => 0,
            (bool) preg_match('/post|blog|news|actu|article|event/', $name) => 3,
            (bool) preg_match('/product|produit|item|shop/', $name) => 2,
            default => 1,
        };
    }
}
