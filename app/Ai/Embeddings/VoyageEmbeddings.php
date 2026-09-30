<?php

namespace App\Ai\Embeddings;

use App\Ai\LlmException;
use Illuminate\Support\Facades\Http;

/** Voyage AI : fournisseur d'embeddings recommande par Anthropic, bon en multilingue. */
class VoyageEmbeddings implements EmbeddingClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
    ) {}

    public function model(): string
    {
        return 'voyage:'.$this->model;
    }

    public function embed(array $texts, bool $isQuery = false): array
    {
        $vectors = [];

        foreach (array_chunk($texts, 64) as $batch) {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->retry(2, 500, throw: false)
                ->post(rtrim($this->baseUrl, '/').'/embeddings', [
                    'input' => array_values($batch),
                    'model' => $this->model,
                    'input_type' => $isQuery ? 'query' : 'document',
                ]);

            if (! $response->successful()) {
                throw new LlmException('Voyage embeddings : HTTP '.$response->status().' '.$response->json('detail', ''));
            }

            foreach ($response->json('data') as $row) {
                $vectors[] = $row['embedding'];
            }
        }

        return $vectors;
    }
}
