<?php

namespace App\Ingestion;

use App\Ai\Embeddings\EmbeddingClient;
use App\Ingestion\Catalog\ProductSheet;
use App\Ingestion\Crawler\CrawlRun;
use App\Ingestion\Extractors\FileExtractor;
use App\Ingestion\Extractors\ImageReader;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Services\ProductCatalog;
use App\Retrieval\Vector;
use App\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Source -> documents -> extraits -> vecteurs.
 * Rejouable a volonte : les anciens documents ne sont remplaces qu'une fois le nouveau contenu
 * extrait et vectorise avec succes, donc un echec de re-synchronisation ne vide jamais le bot.
 */
class IngestionPipeline
{
    public function __construct(
        private readonly Chunker $chunker,
        private readonly EmbeddingClient $embeddings,
        private readonly FileExtractor $files,
        private readonly ImageReader $images,
        private readonly CrawlRun $crawl,
        private readonly ProductCatalog $catalog,
    ) {}

    /**
     * Indexe une source. Un site se lit par tranches : avec `$seconds`, la lecture s'arrête au bout de ce temps, la source
     * reste « en cours » et la tranche suivante (page ouverte, planificateur) reprend où celle-ci s'est arrêtée. Sans limite
     * (ligne de commande, tests), tout se fait d'un coup.
     */
    public function run(Source $source, ?float $seconds = null): void
    {
        $source->forceFill(['status' => Source::PROCESSING, 'error' => null])->save();

        try {
            $crawled = null;

            if ($source->type === Source::TYPE_URL) {
                $crawled = $this->crawlSite($source, $seconds);
                if ($crawled === null) {
                    return; // lecture en cours, reprise à la prochaine tranche
                }
                // Les menus et pieds de page se répètent d'une page à l'autre : ils sont retirés des pages, pas des fiches produit
                // (leurs lignes « Disponibilité : En stock » se répètent aussi, et doivent rester dans chaque fiche).
                $documents = [...$this->dropBoilerplate($crawled['documents']), ...$crawled['sheets']];
            } else {
                $documents = $this->dropBoilerplate($this->collect($source));
            }

            if ($documents === []) {
                throw new IngestionException("Aucun contenu exploitable n'a été trouvé.");
            }

            $stats = $this->store($source, $documents);

            if ($crawled !== null) {
                $stats = $this->withCrawlStats($stats, $crawled);
            }

            $source->forceFill([
                'status' => Source::READY,
                'stats' => $stats,
                'progress' => null,
                'error' => null,
                'last_synced_at' => now(),
            ])->save();

            if ($crawled !== null) {
                $this->crawl->release($source);
            }
        } catch (\Throwable $e) {
            $expected = $e instanceof IngestionException;

            $source->forceFill([
                'status' => Source::FAILED,
                'progress' => null,
                'error' => $expected ? $e->getMessage() : 'Erreur technique : '.Str::limit($e->getMessage(), 240),
            ])->save();

            if (! $expected) {
                report($e);
            }
        }
    }

    /** @return list<ExtractedDocument> */
    private function collect(Source $source): array
    {
        $payload = $source->payload ?? [];

        return match ($source->type) {
            Source::TYPE_FILE => [$this->files->extract(
                $this->path($payload),
                pathinfo($payload['original_name'] ?? $payload['path'], PATHINFO_EXTENSION),
                $source->name,
                $source->workspace?->currency,
            )],
            Source::TYPE_IMAGE => [$this->images->read($this->path($payload), $source->name)],
            Source::TYPE_TEXT, Source::TYPE_FACEBOOK => [new ExtractedDocument(
                $source->name,
                Text::clean((string) ($payload['content'] ?? '')),
                $payload['url'] ?? null,
            )],
            Source::TYPE_QA => [new ExtractedDocument(
                Text::limit((string) $payload['question'], 120),
                'Question : '.trim($payload['question'])."\nRéponse : ".trim($payload['answer']),
            )],
            default => throw new IngestionException("Type de source inconnu : {$source->type}"),
        };
    }

    /**
     * Lit des pages du site pendant `$seconds`. Rend null tant que la lecture n'est pas finie ; sinon les pages, les
     * produits (déjà enregistrés au catalogue de l'assistant, avec leur fiche écrite) et le bilan de la lecture.
     *
     * @return array{documents:list<ExtractedDocument>, sheets:list<ExtractedDocument>, products:int, photos:int, stats:array<string,mixed>}|null
     */
    private function crawlSite(Source $source, ?float $seconds): ?array
    {
        if (! $this->crawl->started($source)) {
            $this->crawl->start($source);
            $source->refresh();
        }

        $progress = $this->crawl->step($source, $seconds ?? 900.0);
        if (! $progress['finished']) {
            return null;
        }

        $harvest = $this->crawl->harvest($source);
        if ($harvest['documents'] === [] && $harvest['products'] === []) {
            throw new IngestionException('Aucun texte exploitable sur ce site. Il est peut-être entièrement construit en JavaScript : collez son contenu ou importez un document.');
        }

        $sheets = [];
        $photos = 0;

        // Chaque produit a sa fiche écrite (nom, prix et disponibilité dans le même extrait) et une référence pour sa photo.
        foreach ($this->catalog->sync($source, $harvest['products']) as ['product' => $product, 'item' => $item]) {
            $sheets[] = ProductSheet::document($product, $item->ref());
            $photos += $product->image ? 1 : 0;
        }
        if ($overview = ProductSheet::overview($harvest['products'], $source->name)) {
            $sheets[] = $overview;
        }

        return ['documents' => $harvest['documents'], 'sheets' => $sheets, 'products' => count($harvest['products']), 'photos' => $photos, 'stats' => $harvest['stats']];
    }

    /**
     * Le bilan d'un site : pages lues sur pages trouvées, produits et photos, pages ignorées, et si la limite de l'offre
     * a empêché de tout lire.
     *
     * @param  array<string,mixed>  $stats
     * @param  array{documents:list<ExtractedDocument>, sheets:list<ExtractedDocument>, products:int, photos:int, stats:array<string,mixed>}  $crawled
     * @return array<string,mixed>
     */
    private function withCrawlStats(array $stats, array $crawled): array
    {
        $progress = $crawled['stats'];

        $stats['pages'] = $progress['read'];
        $stats['found'] = $progress['found'];
        $stats['limit'] = $progress['limit'];
        $stats['plan_limit'] = $progress['plan_limit'];
        $stats['truncated'] = $progress['truncated'];
        $stats['ignored'] = $progress['utility'] + $progress['duplicates'];
        $stats['failed'] = $progress['failed'];

        if ($crawled['products'] > 0) {
            $stats['products'] = $crawled['products'];
            $stats['photos'] = $crawled['photos'];
        } else {
            unset($stats['products']);
        }

        return $stats;
    }

    private function path(array $payload): string
    {
        $disk = Storage::disk(config('platform.uploads.disk'));

        if (empty($payload['path']) || ! $disk->exists($payload['path'])) {
            throw new IngestionException('Le fichier envoyé est introuvable, veuillez le renvoyer.');
        }

        return $disk->path($payload['path']);
    }

    /**
     * Les pages d'un meme site partagent menus, pieds de page et mentions legales : une ligne qui
     * revient sur 3 pages ou plus est conservee sur la premiere seulement, pour ne pas polluer la recherche.
     *
     * @param  list<ExtractedDocument>  $documents
     * @return list<ExtractedDocument>
     */
    private function dropBoilerplate(array $documents): array
    {
        $documents = array_values(array_filter($documents, fn ($d) => mb_strlen(trim($d->text)) > 0));

        if (count($documents) < 4) {
            return $documents;
        }

        $counts = [];
        foreach ($documents as $document) {
            foreach (array_unique($this->lines($document->text)) as $line) {
                $counts[$line] = ($counts[$line] ?? 0) + 1;
            }
        }

        $seen = [];
        $clean = [];
        foreach ($documents as $document) {
            $kept = [];
            foreach (explode("\n", $document->text) as $line) {
                $key = $this->lineKey($line);
                $repeated = $key !== '' && ($counts[$key] ?? 0) >= 3 && mb_strlen($key) < 200;

                if ($repeated && isset($seen[$key])) {
                    continue;
                }
                if ($repeated) {
                    $seen[$key] = true;
                }
                $kept[] = $line;
            }
            $clean[] = new ExtractedDocument($document->title, Text::clean(implode("\n", $kept)), $document->url, $document->meta);
        }

        return array_values(array_filter($clean, fn ($d) => mb_strlen($d->text) >= 40));
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map(fn ($l) => $this->lineKey($l), explode("\n", $text))));
    }

    private function lineKey(string $line): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($line)));
    }

    /**
     * @param  list<ExtractedDocument>  $documents
     * @return array{pages:int, chunks:int, tokens:int, chars:int, products?:int, notes?:list<string>}
     */
    private function store(Source $source, array $documents): array
    {
        $prepared = [];
        $texts = [];

        foreach ($documents as $document) {
            $chunks = $this->chunker->split($document->text);
            if ($chunks === []) {
                continue;
            }
            $prepared[] = ['document' => $document, 'chunks' => $chunks];
            foreach ($chunks as $chunk) {
                $prefix = Text::breadcrumb($document->title, $chunk['heading']);
                $texts[] = $prefix === '' ? $chunk['content'] : $prefix."\n".$chunk['content'];
            }
        }

        if ($texts === []) {
            throw new IngestionException('Le contenu est trop court pour être indexé.');
        }

        // Vectorisation hors transaction : les appels reseau ne doivent pas garder la base verrouillee.
        $vectors = [];
        foreach (array_chunk($texts, (int) config('platform.rag.embed_batch')) as $batch) {
            array_push($vectors, ...$this->embeddings->embed($batch));
        }

        $model = $this->embeddings->model();
        $totalChunks = 0;
        $totalTokens = 0;
        $totalChars = 0;
        $cursor = 0;

        DB::transaction(function () use ($source, $prepared, $vectors, $model, &$totalChunks, &$totalTokens, &$totalChars, &$cursor) {
            Chunk::withoutGlobalScopes()->where('source_id', $source->id)->delete();
            Document::withoutGlobalScopes()->where('source_id', $source->id)->delete();

            foreach ($prepared as $item) {
                /** @var ExtractedDocument $document */
                $document = $item['document'];

                $record = Document::withoutGlobalScopes()->create([
                    'workspace_id' => $source->workspace_id,
                    'bot_id' => $source->bot_id,
                    'source_id' => $source->id,
                    'title' => $document->title,
                    'url' => $document->url ? Str::limit($document->url, 2000, '') : null,
                    'content' => $document->text,
                    'content_hash' => hash('sha256', $document->text),
                ]);
                $totalChars += mb_strlen($document->text);

                $rows = [];
                $now = now();
                foreach ($item['chunks'] as $chunk) {
                    $tokens = Text::estimateTokens($chunk['content']);
                    $totalTokens += $tokens;
                    $rows[] = [
                        'workspace_id' => $source->workspace_id,
                        'bot_id' => $source->bot_id,
                        'source_id' => $source->id,
                        'document_id' => $record->id,
                        'position' => $chunk['position'],
                        'heading_path' => $chunk['heading'] ? Str::limit($chunk['heading'], 480, '') : null,
                        'content' => $chunk['content'],
                        'token_count' => $tokens,
                        'embedding' => Vector::encode($vectors[$cursor++]),
                        'embedding_model' => $model,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($rows, 50) as $batch) {
                    Chunk::withoutGlobalScopes()->insert($batch);
                }
                $totalChunks += count($rows);
            }
        });

        $stats = [
            'pages' => count($prepared),
            'chunks' => $totalChunks,
            'tokens' => $totalTokens,
            'chars' => $totalChars,
        ];

        // Un tableau lu comme un catalogue : combien de produits, et ce qui mérite l'attention du client.
        $products = array_sum(array_map(fn ($item) => (int) ($item['document']->meta['products'] ?? 0), $prepared));
        if ($source->type === Source::TYPE_URL) {
            $products = 0; // le nombre de produits d'un site est celui du catalogue (voir withCrawlStats)
        }
        $notes = [];
        foreach ($prepared as $item) {
            array_push($notes, ...($item['document']->meta['notes'] ?? []));
        }
        $notes = array_values(array_unique($notes));
        if ($products > 0) {
            $stats['products'] = $products;
        }
        if ($notes !== []) {
            $stats['notes'] = array_slice($notes, 0, 8);
        }

        return $stats;
    }
}
