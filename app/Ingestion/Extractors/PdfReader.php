<?php

namespace App\Ingestion\Extractors;

use App\Ai\Llm\LlmClient;
use App\Ai\LlmException;
use App\Ingestion\ExtractedDocument;
use App\Ingestion\IngestionException;
use App\Support\Text;
use Smalot\PdfParser\Parser;

/**
 * Extraction du texte d'un PDF. Si le PDF est une simple image (scan), on bascule sur la lecture
 * native des PDF par Claude (OCR compris) plutot que d'indexer du vide.
 */
class PdfReader
{
    private const MAX_VISION_BYTES = 30_000_000;

    private const INSTRUCTION = "Tu prépares le contenu d'une base de connaissances pour l'assistant d'une entreprise. "
        .'Transcris fidèlement tout le texte de ce document en Markdown (titres avec #, listes, tableaux sous forme de lignes « élément : valeur »). '
        ."N'invente rien, ne résume pas, n'ajoute aucun commentaire : écris [illisible] pour un passage illisible.";

    public function __construct(private readonly LlmClient $llm) {}

    public function read(string $path, string $fallbackTitle): ExtractedDocument
    {
        $text = '';
        $pages = 1;
        $title = null;

        try {
            $pdf = (new Parser)->parseFile($path);
            $text = Text::clean($pdf->getText());
            $pages = max(1, count($pdf->getPages()));
            $meta = $pdf->getDetails()['Title'] ?? null;
            $title = is_string($meta) && trim($meta) !== '' ? trim($meta) : null;
        } catch (\Throwable) {
            // PDF protege ou atypique : on tente la lecture native ci-dessous.
        }

        // Moins de ~40 caracteres par page : c'est presque certainement un scan.
        if (mb_strlen($text) >= 40 * $pages) {
            return new ExtractedDocument($title ?? $fallbackTitle, $text);
        }

        if (filesize($path) > self::MAX_VISION_BYTES) {
            throw new IngestionException('Ce PDF est un scan trop volumineux pour être lu (30 Mo maximum).');
        }

        try {
            $ocr = $this->llm->transcribe(file_get_contents($path), 'application/pdf', self::INSTRUCTION);
        } catch (LlmException $e) {
            if ($text !== '') {
                return new ExtractedDocument($title ?? $fallbackTitle, $text);
            }
            throw new IngestionException('Ce PDF ne contient pas de texte sélectionnable (scan). '.$e->getMessage());
        }

        return new ExtractedDocument($title ?? $fallbackTitle, Text::clean($ocr));
    }
}
