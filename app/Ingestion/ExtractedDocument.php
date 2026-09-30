<?php

namespace App\Ingestion;

final class ExtractedDocument
{
    public function __construct(
        public readonly ?string $title,
        public readonly string $text,
        public readonly ?string $url = null,
    ) {}
}
