<?php

namespace App\Retrieval;

use App\Models\Bot;

/**
 * Port de recherche hybride. L'implementation actuelle (SqlHybridStore) calcule cosinus et BM25
 * en PHP, ce qui suffit jusqu'a quelques milliers d'extraits par bot. Pour l'echelle,
 * une implementation PostgreSQL (pgvector + tsvector) se branche ici sans toucher au reste.
 */
interface HybridStore
{
    /**
     * @param  list<float>  $queryVector
     * @return list<array{id:int, score:float, vector:float, lexical:float}> tries par score decroissant
     */
    public function search(Bot $bot, string $query, array $queryVector, string $embeddingModel, int $limit): array;
}
