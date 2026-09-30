<?php

namespace App\Ai\Embeddings;

interface EmbeddingClient
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>> un vecteur par texte, dans le meme ordre
     */
    public function embed(array $texts, bool $isQuery = false): array;

    /** Identifiant stocke avec chaque chunk : on ne compare jamais des vecteurs de modeles differents. */
    public function model(): string;
}
