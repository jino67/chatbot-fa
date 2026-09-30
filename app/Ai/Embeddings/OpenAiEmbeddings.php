<?php

namespace App\Ai\Embeddings;

use App\Ai\LlmException;
use Illuminate\Support\Facades\Http;

/** Embeddings via une API compatible OpenAI (OpenAI, Azure, serveur local...). */
class OpenAiEmbeddings implements EmbeddingClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
    ) {}

    public function model(): string
    {
        return 'openai:'.$this->model;
    }

    public function embed(array $texts, bool $isQuery = false): array
    {
        $vectors = [];

        foreach (array_chunk($texts, 96) as $batch) {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->retry(2, 500, throw: false)
                ->post(rtrim($this->baseUrl, '/').'/embeddings', [
                    'input' => array_values($batch),
                    'model' => $this->model,
                ]);

            if (! $response->successful()) {
                throw new LlmException('OpenAI embeddings : HTTP '.$response->status().' '.$response->json('error.message', ''));
            }

            $rows = $response->json('data');
            usort($rows, fn ($a, $b) => $a['index'] <=> $b['index']);

            foreach ($rows as $row) {
                $vectors[] = $row['embedding'];
            }
        }

        return $vectors;
    }
}
