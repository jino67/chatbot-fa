<?php

namespace App\Retrieval;

use App\Support\Text;

final class RetrievedChunk
{
    public function __construct(
        public readonly int $chunkId,
        public readonly int $sourceId,
        public readonly ?string $title,
        public readonly ?string $url,
        public readonly ?string $heading,
        public readonly string $content,
        public readonly float $score,
        public readonly float $vectorScore,
        public readonly float $lexicalScore,
    ) {}

    /** Reference legere conservee avec le message (citations, debug du playground). */
    public function toReference(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'url' => $this->url,
            'heading' => $this->heading,
            'score' => round($this->score, 3),
        ];
    }

    public function label(): string
    {
        return Text::breadcrumb($this->title, $this->heading);
    }
}
