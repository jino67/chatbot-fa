<?php

namespace App\Ingestion\Extractors;

use DOMDocument;
use DOMXPath;

/**
 * Se branche sur la lecture d'une page par HtmlToText : `before` voit la page entière (scripts, données structurées,
 * balises meta) ; `after` voit ce qui reste une fois le bruit retiré, et peut encore modifier le document avant qu'il
 * soit transformé en texte.
 */
interface PageTransformer
{
    public function before(DOMDocument $dom, DOMXPath $xpath, ?string $baseUrl): void;

    public function after(DOMDocument $dom, DOMXPath $xpath, ?string $baseUrl): void;
}
