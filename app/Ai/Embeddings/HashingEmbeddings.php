<?php

namespace App\Ai\Embeddings;

use App\Retrieval\Vector;
use App\Support\Text;

/**
 * Embeddings locaux deterministes (feature hashing de mots et de bigrammes).
 * Aucun appel reseau, aucune cle : sert aux demos hors ligne et aux tests.
 * La qualite semantique est celle d'un sac de mots : en production, utiliser voyage ou openai.
 */
class HashingEmbeddings implements EmbeddingClient
{
    public function __construct(private readonly int $dimensions = 512) {}

    public function model(): string
    {
        return 'hashing-'.$this->dimensions;
    }

    public function embed(array $texts, bool $isQuery = false): array
    {
        return array_map(fn (string $text) => $this->vectorize($text), $texts);
    }

    /** @return list<float> */
    private function vectorize(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $tokens = Text::tokens($text);

        $this->accumulate($vector, array_count_values($tokens), 1.0);

        $bigrams = [];
        for ($i = 0, $n = count($tokens) - 1; $i < $n; $i++) {
            $bigrams[$tokens[$i].'_'.$tokens[$i + 1]] = ($bigrams[$tokens[$i].'_'.$tokens[$i + 1]] ?? 0) + 1;
        }
        $this->accumulate($vector, $bigrams, 0.5);

        return Vector::normalize($vector);
    }

    /** @param array<string,int> $counts */
    private function accumulate(array &$vector, array $counts, float $weight): void
    {
        foreach ($counts as $feature => $count) {
            $hash = crc32((string) $feature);
            $index = $hash % $this->dimensions;
            $sign = (($hash >> 16) & 1) ? 1.0 : -1.0;
            $vector[$index] += $sign * $weight * log(1 + $count);
        }
    }
}
