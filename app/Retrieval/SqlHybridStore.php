<?php

namespace App\Retrieval;

use App\Models\Bot;
use App\Models\Chunk;
use App\Support\Text;

class SqlHybridStore implements HybridStore
{
    private const K1 = 1.5;

    private const B = 0.75;

    public function search(Bot $bot, string $query, array $queryVector, string $embeddingModel, int $limit): array
    {
        // Filtrage explicite par workspace ET par bot : jamais de dependance a un scope implicite ici.
        $chunks = Chunk::withoutGlobalScopes()
            ->where('workspace_id', $bot->workspace_id)
            ->where('bot_id', $bot->id)
            ->with('document:id,title')
            ->get(['id', 'document_id', 'heading_path', 'content', 'embedding', 'embedding_model']);

        if ($chunks->isEmpty()) {
            return [];
        }

        $lexical = $this->bm25($chunks, $query);

        // Sans vecteur de requete (fournisseur d'embeddings en panne) : recherche par mots exacts seule,
        // avec un seuil plus bas puisque le score lexical n'est plus pondere par le score vectoriel.
        $degraded = $queryVector === [];
        $alpha = $degraded ? 0.0 : (float) config('platform.rag.alpha');
        $minScore = (float) config('platform.rag.min_score') * ($degraded ? 0.5 : 1.0);
        $scored = [];

        foreach ($chunks as $chunk) {
            $vector = 0.0;
            if (! $degraded && $chunk->embedding && $chunk->embedding_model === $embeddingModel) {
                $vector = max(0.0, Vector::cosine($queryVector, Vector::decode($chunk->embedding)));
            }

            $lex = $lexical[$chunk->id] ?? 0.0;
            $score = $alpha * $vector + (1 - $alpha) * $lex;

            if ($score >= $minScore) {
                $scored[] = ['id' => $chunk->id, 'score' => $score, 'vector' => $vector, 'lexical' => $lex];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * BM25 sur les extraits du bot, normalise dans [0, 1[ par saturation (s / (s + 6)).
     *
     * @return array<int,float> chunk id => score lexical
     */
    private function bm25($chunks, string $query): array
    {
        $terms = array_values(array_unique(Text::tokens($query)));
        if ($terms === []) {
            return [];
        }

        $docs = [];
        $df = [];
        $totalLength = 0;

        foreach ($chunks as $chunk) {
            $tokens = Text::tokens(($chunk->document?->title ?? '').' '.($chunk->heading_path ?? '').' '.$chunk->content);
            $tf = array_count_values($tokens);
            $docs[$chunk->id] = ['tf' => $tf, 'len' => count($tokens)];
            $totalLength += count($tokens);

            foreach ($terms as $term) {
                if (isset($tf[$term])) {
                    $df[$term] = ($df[$term] ?? 0) + 1;
                }
            }
        }

        $n = count($docs);
        $avg = max(1.0, $totalLength / $n);
        $out = [];

        foreach ($docs as $id => $doc) {
            $score = 0.0;
            foreach ($terms as $term) {
                $tf = $doc['tf'][$term] ?? 0;
                if ($tf === 0) {
                    continue;
                }
                $idf = log(1 + ($n - $df[$term] + 0.5) / ($df[$term] + 0.5));
                $score += $idf * ($tf * (self::K1 + 1)) / ($tf + self::K1 * (1 - self::B + self::B * $doc['len'] / $avg));
            }
            if ($score > 0) {
                $out[$id] = $score / ($score + 6);
            }
        }

        return $out;
    }
}
