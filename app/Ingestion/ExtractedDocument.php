<?php

namespace App\Ingestion;

final class ExtractedDocument
{
    public function __construct(
        public readonly ?string $title,
        public readonly string $text,
        public readonly ?string $url = null,
        /** @var array{products?:int, notes?:list<string>} lu par le pipeline pour résumer la source au client */
        public readonly array $meta = [],
    ) {}
}
