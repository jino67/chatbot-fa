<?php

namespace App\Ingestion;

use App\Ai\Embeddings\EmbeddingClient;
use App\Ingestion\Crawler\SiteCrawler;
use App\Ingestion\Extractors\FileExtractor;
use App\Ingestion\Extractors\ImageReader;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
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
        private readonly SiteCrawler $crawler,
    ) {}

    public function run(Source $source): void
    {
        $source->forceFill(['status' => Source::PROCESSING, 'error' => null])->save();

        try {
            $documents = $this->dropBoilerplate($this->collect($source));

            if ($documents === []) {
                throw new IngestionException("Aucun contenu exploitable n'a été trouvé.");
            }

            $stats = $this->store($source, $documents);

            $source->forceFill([
                'status' => Source::READY,
                'stats' => $stats,
                'error' => null,
                'last_synced_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $expected = $e instanceof IngestionException;

            $source->forceFill([
                'status' => Source::FAILED,
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
            Source::TYPE_URL => $this->crawlSite($source),
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

    /** @return list<ExtractedDocument> */
    private function crawlSite(Source $source): array
    {
        $payload = $source->payload;
        $limit = $source->workspace?->limits()['pages_per_crawl'] ?? 20;
        $max = min($limit, (int) ($payload['max_pages'] ?? $limit));

        return iterator_to_array(
            $this->crawler->crawl($payload['url'], $max, singlePage: ($payload['mode'] ?? 'site') === 'page'),
            false
        );
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
