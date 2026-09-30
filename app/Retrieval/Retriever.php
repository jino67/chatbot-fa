<?php

namespace App\Retrieval;

use App\Ai\Embeddings\EmbeddingClient;
use App\Models\Bot;
use App\Models\Chunk;

class Retriever
{
    public function __construct(
        private readonly HybridStore $store,
        private readonly EmbeddingClient $embeddings,
    ) {}

    /**
     * @return list<RetrievedChunk> extraits pertinents, meilleur score en premier (vide si rien ne depasse le seuil)
     */
    public function retrieve(Bot $bot, string $query, ?int $limit = null): array
    {
        $limit ??= (int) config('platform.rag.top_k');
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        // Fournisseur d'embeddings en panne : on degrade vers la recherche par mots exacts plutot que d'echouer.
        try {
            $vector = $this->embeddings->embed([$query], isQuery: true)[0];
        } catch (\Throwable $e) {
            report($e);
            $vector = [];
        }

        $hits = $this->store->search($bot, $query, $vector, $this->embeddings->model(), $limit);

        if ($hits === []) {
            return [];
        }

        $chunks = Chunk::withoutGlobalScopes()
            ->where('bot_id', $bot->id)
            ->whereIn('id', array_column($hits, 'id'))
            ->with('document:id,title,url')
            ->get()
            ->keyBy('id');

        $results = [];
        foreach ($hits as $hit) {
            $chunk = $chunks[$hit['id']] ?? null;
            if (! $chunk) {
                continue;
            }
            $results[] = new RetrievedChunk(
                chunkId: $chunk->id,
                sourceId: $chunk->source_id,
                title: $chunk->document?->title,
                url: $chunk->document?->url,
                heading: $chunk->heading_path,
                content: $chunk->content,
                score: $hit['score'],
                vectorScore: $hit['vector'],
                lexicalScore: $hit['lexical'],
            );
        }

        return $results;
    }
}
