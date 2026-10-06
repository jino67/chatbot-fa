<?php

namespace App\Ingestion\Crawler;

use App\Ingestion\Catalog\CatalogProduct;
use App\Ingestion\ExtractedDocument;
use App\Ingestion\IngestionException;
use App\Models\CatalogItem;
use App\Models\Source;
use App\Models\SourcePage;
use App\Support\Text;

/**
 * Lecture d'un site, reprenable. L'état vit en base (table `source_pages`), pas dans la mémoire d'une requête : une
 * tranche lit des pages pendant quelques secondes puis rend la main, la suivante repart où la précédente s'est arrêtée.
 * Sur un hébergement mutualisé, où une requête web est coupée au bout de quelques dizaines de secondes, c'est ce qui
 * permet de lire un site de plusieurs centaines de pages sans jamais rien perdre.
 *
 * Ordre de lecture : la page de départ, les pages d'information (contact, livraison, FAQ), les catégories, les produits,
 * puis le reste. Les pages sans contenu utile (panier, connexion, formulaires de commande) et les doublons ne comptent
 * pas dans la limite de pages de l'offre.
 */
final class CrawlRun
{
    private const LIMIT_NOTE = "limite de l'offre";

    /** Nombre d'adresses gardées au plus pour un site : au-delà, on cesse d'en chercher de nouvelles. */
    private const MAX_ROWS = 4000;

    public function __construct(private readonly PageReader $reader) {}

    public function started(Source $source): bool
    {
        return is_array($source->progress) && isset($source->progress['limit']);
    }

    /**
     * Prépare la lecture : page de départ et plan du site.
     *
     * @throws IngestionException|UnsafeUrlException
     */
    public function start(Source $source): void
    {
        $payload = $source->payload ?? [];
        $start = Url::normalize((string) ($payload['url'] ?? ''));
        UrlRules::assertCrawlable($start);
        SafeUrl::assertPublic($start);

        $single = ($payload['mode'] ?? 'site') === 'page';
        $plan = max(1, (int) ($source->workspace?->limits()['pages_per_crawl'] ?? 20));
        $limit = $single ? 1 : min($plan, max(1, (int) ($payload['max_pages'] ?? $plan)));

        $robots = config('platform.crawler.respect_robots') ? RobotsTxt::fetch($start) : new RobotsTxt;

        SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->delete();
        $this->insert($source, [[$start, 0, 0]]);

        if (! $single) {
            $rows = [];
            $useless = [];
            foreach (Sitemap::urls($start, $robots, self::MAX_ROWS - 100) as $url) {
                if (UrlRules::skipReason($url) === null) {
                    $rows[] = [$url, 1, UrlRules::priority($url)];
                } elseif (count($useless) < 300) {
                    $useless[] = [$url, 1, 9];
                }
            }
            $this->insert($source, $rows);
            $this->insert($source, $useless, SourcePage::SKIPPED, 'page utilitaire');
        }

        $source->forceFill(['progress' => [
            'limit' => $limit,
            'plan_limit' => $plan,
            'robots' => $robots->toArray(),
            'started_at' => now()->toIso8601String(),
        ]])->save();
    }

    /**
     * Lit des pages pendant `$seconds` secondes au plus (une page commencée va toujours à son terme).
     *
     * @return array<string,mixed> l'avancement
     *
     * @throws IngestionException|UnsafeUrlException si la page de départ est illisible
     */
    public function step(Source $source, float $seconds): array
    {
        $progress = $source->progress ?? [];
        $payload = $source->payload ?? [];
        $start = Url::normalize((string) ($payload['url'] ?? ''));
        $single = ($payload['mode'] ?? 'site') === 'page';
        $robots = RobotsTxt::fromArray($progress['robots'] ?? []);
        $limit = (int) ($progress['limit'] ?? 20);
        $maxDepth = (int) config('platform.crawler.max_depth');
        $delay = (int) config('platform.crawler.delay_ms') * 1000;
        $currency = (string) ($source->workspace?->currency ?: 'XOF');
        $deadline = microtime(true) + $seconds;

        do {
            $done = $this->count($source, SourcePage::DONE);
            if ($done >= $limit) {
                SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->where('status', SourcePage::PENDING)
                    ->update(['status' => SourcePage::SKIPPED, 'note' => self::LIMIT_NOTE]);

                break;
            }

            $page = SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->where('status', SourcePage::PENDING)
                ->orderBy('priority')->orderBy('depth')->orderBy('id')->first();
            if (! $page) {
                break;
            }

            $this->read($source, $page, $start, $robots, $single, $maxDepth, $currency);
            usleep($delay);
        } while (microtime(true) < $deadline);

        return $this->progress($source);
    }

    /** @return array{limit:int, plan_limit:int, read:int, found:int, pending:int, failed:int, utility:int, duplicates:int, truncated:bool, finished:bool} */
    public function progress(Source $source): array
    {
        $rows = SourcePage::withoutGlobalScopes()->where('source_id', $source->id)
            ->selectRaw('status, note, count(*) as total')->groupBy('status', 'note')->get();

        $count = fn (string $status, ?string $note = null) => (int) $rows->where('status', $status)
            ->when($note !== null, fn ($r) => $r->where('note', $note))->sum('total');

        $read = $count(SourcePage::DONE);
        $pending = $count(SourcePage::PENDING);
        $truncated = $count(SourcePage::SKIPPED, self::LIMIT_NOTE);

        return [
            'limit' => (int) ($source->progress['limit'] ?? 0),
            'plan_limit' => (int) ($source->progress['plan_limit'] ?? 0),
            'read' => $read,
            // Les pages trouvées qui valent d'être lues : lues, à lire, en échec, ou laissées de côté faute de place.
            'found' => $read + $pending + $count(SourcePage::FAILED) + $truncated,
            'pending' => $pending,
            'failed' => $count(SourcePage::FAILED),
            'utility' => $count(SourcePage::SKIPPED, 'page utilitaire'),
            'duplicates' => $count(SourcePage::SKIPPED, 'doublon'),
            'truncated' => $truncated > 0,
            'finished' => $pending === 0,
        ];
    }

    /**
     * Ce qui a été lu : les pages (sauf les fiches produit, remplacées par une fiche écrite) et les produits, dédoublonnés.
     *
     * @return array{documents:list<ExtractedDocument>, products:list<CatalogProduct>, stats:array<string,mixed>}
     */
    public function harvest(Source $source): array
    {
        $pages = SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->where('status', SourcePage::DONE)
            ->orderBy('priority')->orderBy('depth')->orderBy('id')->get();

        $documents = [];
        $details = [];
        $listed = [];

        foreach ($pages as $page) {
            $products = $page->products ?? [];

            foreach ($products['items'] ?? [] as $item) {
                $listed[] = CatalogProduct::fromArray($item);
            }

            if (! empty($products['main'])) {
                $details[] = CatalogProduct::fromArray($products['main']);

                continue; // la fiche écrite du produit remplace le texte de la page
            }

            $documents[] = new ExtractedDocument($page->title ?: $page->url, (string) $page->content, $page->url);
        }

        return [
            'documents' => $documents,
            'products' => $this->merge([...$details, ...$listed]),
            'stats' => $this->progress($source),
        ];
    }

    /** Libère l'espace : le contenu est maintenant dans les extraits, ne restent que les adresses et le résultat. */
    public function release(Source $source): void
    {
        SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->update(['content' => null, 'products' => null]);
    }

    /* ---------- Lecture d'une page ---------- */

    private function read(Source $source, SourcePage $page, string $start, RobotsTxt $robots, bool $single, int $maxDepth, string $currency): void
    {
        $isStart = UrlRules::dedupeKey($page->url) === UrlRules::dedupeKey($start);

        if (! $robots->allows($page->url)) {
            if ($isStart) {
                throw new IngestionException("Ce site interdit l'exploration automatique (robots.txt). Ajoutez son contenu en collant le texte ou en important un document.");
            }

            $this->close($page, SourcePage::SKIPPED, 'interdit par robots.txt');

            return;
        }

        try {
            $res = SafeHttp::get($page->url);
        } catch (UnsafeUrlException $e) {
            if ($isStart) {
                throw $e;
            }
            $this->close($page, SourcePage::FAILED, 'adresse refusée');

            return;
        } catch (\Throwable $e) {
            if ($isStart) {
                throw new IngestionException("Impossible d'ouvrir {$page->url} : ".$e->getMessage());
            }
            $this->close($page, SourcePage::FAILED, 'injoignable');

            return;
        }

        if ($res['status'] !== 200 || ! str_contains(strtolower($res['type']), 'html')) {
            if ($isStart) {
                throw new IngestionException("{$page->url} a répondu HTTP {$res['status']} : page introuvable, protégée ou qui n'est pas une page web.");
            }
            $this->close($page, SourcePage::FAILED, 'HTTP '.$res['status'], $res['status']);

            return;
        }

        // Une redirection ne doit pas nous faire sortir du site.
        if (! Url::sameSite($start, $res['url'])) {
            $this->close($page, SourcePage::SKIPPED, 'redirection hors du site');

            return;
        }

        $data = $this->reader->read($res['body'], $res['url'], $currency);

        // Une page « Contact » tient parfois en une ligne : on ne jette que le vide.
        if (mb_strlen($data['text']) < 40 && $data['products'] === []) {
            $this->close($page, SourcePage::SKIPPED, 'page vide');

            return;
        }

        // La même page sous une autre adresse (majuscules, « duo-visage » et « Duo-visage », canonique) n'est lue qu'une fois.
        $hash = sha1(Text::fold(preg_replace('/\s+/u', ' ', $data['text'])));
        $canonical = $data['canonical'];
        $duplicate = SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->where('status', SourcePage::DONE)->where('content_hash', $hash)->exists()
            || ($canonical && UrlRules::dedupeKey($canonical) !== UrlRules::dedupeKey($res['url']) && Url::sameSite($start, $canonical)
                && SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->where('status', SourcePage::DONE)->where('url_hash', sha1(UrlRules::dedupeKey($canonical)))->exists());
        if ($duplicate && ! $isStart) {
            $this->close($page, SourcePage::SKIPPED, 'doublon');

            return;
        }

        $page->forceFill([
            'status' => SourcePage::DONE,
            'http_status' => 200,
            'title' => $data['title'] ? Text::limit($data['title'], 250) : null,
            'content' => $data['text'],
            'content_hash' => $hash,
            'products' => [
                'main' => $data['main']?->toArray(),
                'items' => array_map(fn (CatalogProduct $p) => $p->toArray(), $data['products']),
                'listing' => $data['listing'],
            ],
            'note' => null,
            'fetched_at' => now(),
        ])->save();

        if (! $single && $page->depth < $maxDepth) {
            $this->discover($source, $page, $start, $data['links']);
        }
    }

    /** @param list<string> $links */
    private function discover(Source $source, SourcePage $page, string $start, array $links): void
    {
        $total = SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->count();
        if ($total >= self::MAX_ROWS) {
            return;
        }

        $rows = [];
        $utility = [];
        foreach ($links as $link) {
            $link = Url::normalize($link);
            if (! Url::sameSite($start, $link)) {
                continue;
            }
            $reason = UrlRules::skipReason($link);
            if ($reason === null) {
                $rows[] = [$link, $page->depth + 1, UrlRules::priority($link)];
            } elseif ($reason === 'page utilitaire' && count($utility) < 40) {
                $utility[] = $link;
            }
        }

        $this->insert($source, $rows);
        $this->insert($source, array_map(fn ($u) => [$u, $page->depth + 1, 9], $utility), SourcePage::SKIPPED, 'page utilitaire');
    }

    /** @param list<array{0:string, 1:int, 2:int}> $rows adresse, profondeur, priorité */
    private function insert(Source $source, array $rows, string $status = SourcePage::PENDING, ?string $note = null): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            SourcePage::withoutGlobalScopes()->insertOrIgnore(array_map(fn ($row) => [
                'workspace_id' => $source->workspace_id,
                'bot_id' => $source->bot_id,
                'source_id' => $source->id,
                'url' => $row[0],
                'url_hash' => sha1(UrlRules::dedupeKey($row[0])),
                'status' => $status,
                'depth' => min(255, $row[1]),
                'priority' => $row[2],
                'note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }
    }

    private function close(SourcePage $page, string $status, string $note, ?int $http = null): void
    {
        $page->forceFill(['status' => $status, 'note' => $note, 'http_status' => $http, 'fetched_at' => now()])->save();
    }

    private function count(Source $source, string $status): int
    {
        return SourcePage::withoutGlobalScopes()->where('source_id', $source->id)->where('status', $status)->count();
    }

    /**
     * Le même produit lu sur plusieurs pages (fiche, liste, accueil) devient un seul produit : la fiche, lue la première,
     * l'emporte, et les listes complètent ce qui lui manque (catégorie, photo). Deux produits qui ont chacun leur page restent
     * deux produits même s'ils portent le même nom (« Duo visage » existe en Réparatrice et en Glow Skin, à deux prix) : leurs
     * noms sont alors précisés par leur catégorie, pour que l'assistant ne les confonde jamais.
     *
     * @param  list<CatalogProduct>  $products
     * @return list<CatalogProduct>
     */
    private function merge(array $products): array
    {
        $byKey = [];
        $byName = [];

        foreach ($products as $product) {
            $key = CatalogItem::keyFor($product);
            $nameKey = $this->nameKey($product->name);

            if (isset($byKey[$key])) {
                $byKey[$key] = $byKey[$key]->mergedWith($product);

                continue;
            }

            // Un produit sans adresse (une carte vue dans une liste) rejoint le produit de même nom, et un produit avec adresse
            // rejoint celui qui n'en avait pas encore ; deux produits qui ont chacun leur adresse ne se rejoignent jamais.
            $target = null;
            foreach ($byName[$nameKey] ?? [] as $candidate) {
                if ($product->link === null || $byKey[$candidate]->link === null) {
                    $target = $candidate;
                    break;
                }
            }

            if ($target === null) {
                $byKey[$key] = $product;
                $byName[$nameKey][] = $key;

                continue;
            }

            $merged = $byKey[$target]->mergedWith($product);
            if ($byKey[$target]->link === null && $product->link !== null) {
                unset($byKey[$target]);
                $byName[$nameKey] = array_values(array_diff($byName[$nameKey], [$target]));
                $key = CatalogItem::keyFor($merged);
                $byName[$nameKey][] = $key;
                $byKey[$key] = $merged;
            } else {
                $byKey[$target] = $merged;
            }
        }

        return $this->disambiguate(array_values($byKey));
    }

    /** @param list<CatalogProduct> $products @return list<CatalogProduct> */
    private function disambiguate(array $products): array
    {
        $groups = [];
        foreach ($products as $i => $product) {
            $groups[$this->nameKey($product->name)][] = $i;
        }

        foreach ($groups as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $categories = array_map(fn ($i) => $products[$i]->category, $indexes);
            $byCategory = count(array_filter($categories)) === count($indexes) && count(array_unique($categories)) === count($indexes);

            foreach ($indexes as $i) {
                $product = $products[$i];
                $label = $byCategory ? $product->category : $this->slug($product->link);
                if ($label !== null && $label !== '') {
                    $products[$i] = new CatalogProduct(...[...$product->toArray(), 'name' => $product->name.' ('.$label.')']);
                }
            }
        }

        return $products;
    }

    private function nameKey(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', Text::fold($name)));
    }

    /** « /produits/Duo-visage-glow-skin » donne « duo visage glow skin ». */
    private function slug(?string $link): ?string
    {
        $last = $link ? basename(rawurldecode((string) parse_url($link, PHP_URL_PATH))) : '';

        return $last !== '' ? mb_strtolower(trim(str_replace(['-', '_'], ' ', $last))) : null;
    }
}
