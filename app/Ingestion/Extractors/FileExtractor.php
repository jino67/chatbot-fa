<?php

namespace App\Ingestion\Extractors;

use App\Ingestion\ExtractedDocument;
use App\Ingestion\IngestionException;
use App\Support\Text;

/** Aiguille un fichier telecharge vers le bon lecteur selon son extension. */
class FileExtractor
{
    public function __construct(
        private readonly DocxReader $docx,
        private readonly PdfReader $pdf,
        private readonly HtmlToText $html,
    ) {}

    public function extract(string $path, string $extension, string $name): ExtractedDocument
    {
        $extension = strtolower($extension);

        return match ($extension) {
            'pdf' => $this->pdf->read($path, $name),
            'docx' => $this->docx->read($path, $name),
            'html', 'htm' => $this->fromHtml($path, $name),
            'csv' => new ExtractedDocument($name, $this->csv($path)),
            'txt', 'md' => new ExtractedDocument($name, Text::clean(file_get_contents($path))),
            default => throw new IngestionException("Format de fichier non pris en charge : .{$extension}"),
        };
    }

    private function fromHtml(string $path, string $name): ExtractedDocument
    {
        $page = $this->html->convert(file_get_contents($path));

        return new ExtractedDocument($page['title'] ?? $name, $page['text']);
    }

    /** Un tableau (liste de prix, catalogue) devient une ligne lisible par article : « Colonne : valeur | ... ». */
    private function csv(string $path): string
    {
        $raw = Text::clean(file_get_contents($path));
        $firstLine = strtok($raw, "\n") ?: '';
        $delimiter = collect([',', ';', "\t", '|'])
            ->sortByDesc(fn ($d) => substr_count($firstLine, $d))
            ->first();

        $rows = [];
        foreach (explode("\n", $raw) as $line) {
            if (trim($line) !== '') {
                $rows[] = str_getcsv($line, $delimiter, '"', '');
            }
        }

        if (count($rows) < 2) {
            return $raw;
        }

        $header = array_map('trim', array_shift($rows));
        $lines = [];

        foreach (array_slice($rows, 0, 5000) as $row) {
            $pairs = [];
            foreach ($row as $i => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $label = $header[$i] ?? '';
                    $pairs[] = $label !== '' ? "{$label} : {$value}" : $value;
                }
            }
            if ($pairs) {
                $lines[] = implode(' | ', $pairs);
            }
        }

        return implode("\n\n", $lines);
    }
}
