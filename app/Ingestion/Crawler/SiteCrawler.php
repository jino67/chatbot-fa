<?php

namespace App\Ingestion\Crawler;

use App\Ingestion\ExtractedDocument;
use App\Ingestion\Extractors\HtmlToText;
use App\Ingestion\IngestionException;
use Generator;
use SplQueue;

/**
 * Explore un site public : sitemap puis liens internes, dans la limite du plan, en respectant robots.txt.
 * Un JavaScript lourd (site 100 % SPA) n'est pas execute : voir docs/ARCHITECTURE.md pour l'option Playwright / Firecrawl.
 */
class SiteCrawler
{
    public function __construct(private readonly HtmlToText $html) {}

    /**
     * @return Generator<int, ExtractedDocument>
     *
     * @throws IngestionException si la premiere page est inaccessible
     */
    public function crawl(string $startUrl, int $maxPages, bool $singlePage = false): Generator
    {
        $start = Url::normalize($startUrl);
        $this->assertCrawlable($start);
        SafeUrl::assertPublic($start);

        $robots = config('platform.crawler.respect_robots') ? RobotsTxt::fetch($start) : new RobotsTxt;
        $maxDepth = (int) config('platform.crawler.max_depth');
        $delay = (int) config('platform.crawler.delay_ms') * 1000;

        $queue = new SplQueue;
        $queue->enqueue([$start, 0]);
        $seen = [$start => true];

        if (! $singlePage) {
            foreach ($this->sitemapUrls($start, $robots, $maxPages * 2) as $url) {
                if (! isset($seen[$url])) {
                    $seen[$url] = true;
                    $queue->enqueue([$url, 1]);
                }
            }
        }

        $yielded = 0;

        while (! $queue->isEmpty() && $yielded < $maxPages) {
            [$url, $depth] = $queue->dequeue();
            $isStart = $url === $start;

            if (! $robots->allows($url)) {
                if ($isStart) {
                    throw new IngestionException("Ce site interdit l'exploration automatique (robots.txt). Ajoutez son contenu en collant le texte ou en important un document.");
                }

                continue;
            }

            try {
                $res = SafeHttp::get($url);
            } catch (UnsafeUrlException $e) {
                if ($isStart) {
                    throw $e;
                }

                continue;
            } catch (\Throwable $e) {
                if ($isStart) {
                    throw new IngestionException("Impossible d'ouvrir {$url} : ".$e->getMessage());
                }

                continue;
            }

            if ($res['status'] !== 200 || ! str_contains(strtolower($res['type']), 'html')) {
                if ($isStart) {
                    throw new IngestionException("{$url} a répondu HTTP {$res['status']} : page introuvable, protégée ou qui n'est pas une page web.");
                }

                continue;
            }

            // Une redirection ne doit pas nous faire sortir du site.
            if (! Url::sameSite($start, $res['url'])) {
                continue;
            }

            $page = $this->html->convert($res['body'], $res['url']);

            // Une page « Contact » tient parfois en une ligne : on ne jette que le vide.
            if (mb_strlen($page['text']) >= 40) {
                $yielded++;
                yield new ExtractedDocument($page['title'], $page['text'], $res['url']);
            }

            if (! $singlePage && $depth < $maxDepth) {
                foreach ($page['links'] as $link) {
                    if (isset($seen[$link]) || ! Url::sameSite($start, $link) || Url::looksLikeBinary($link)) {
                        continue;
                    }
                    $seen[$link] = true;
                    $queue->enqueue([$link, $depth + 1]);
                }
            }

            usleep($delay);
        }

        if ($yielded === 0) {
            throw new IngestionException('Aucun texte exploitable sur ce site. Il est peut-être entièrement construit en JavaScript : collez son contenu ou importez un document.');
        }
    }

    private function assertCrawlable(string $url): void
    {
        $host = Url::host($url);

        foreach (config('platform.crawler.blocked_hosts') as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                throw new UnsafeUrlException(
                    "Les réseaux sociaux ne peuvent pas être explorés automatiquement (conditions d'utilisation de Meta). "
                    .'Utilisez « Page Facebook » pour coller le contenu de la page.'
                );
            }
        }
    }

    /** @return list<string> */
    private function sitemapUrls(string $start, RobotsTxt $robots, int $limit): array
    {
        $p = parse_url($start);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        $candidates = array_values(array_unique([...$robots->sitemaps(), $origin.'/sitemap.xml']));
        $urls = [];
        $fetched = 0;

        $queue = $candidates;
        while ($queue && $fetched < 4 && count($urls) < $limit) {
            $sitemap = array_shift($queue);
            $fetched++;

            try {
                $res = SafeHttp::get($sitemap, 2, 3_000_000);
            } catch (\Throwable) {
                continue;
            }
            if ($res['status'] !== 200 || ! preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $res['body'], $m)) {
                continue;
            }

            $isIndex = stripos($res['body'], '<sitemapindex') !== false;
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc);
                if ($isIndex) {
                    $queue[] = $loc;
                } elseif (Url::sameSite($start, $loc) && ! Url::looksLikeBinary($loc)) {
                    $urls[] = Url::normalize($loc);
                }
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, $limit);
    }
}
