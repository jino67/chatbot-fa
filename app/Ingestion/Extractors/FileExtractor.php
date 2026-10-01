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
        private readonly XlsxReader $xlsx,
        private readonly TableReader $tables,
    ) {}

    /** @param ?string $currency devise de l'espace du client : celle des prix qui n'en portent pas */
    public function extract(string $path, string $extension, string $name, ?string $currency = null): ExtractedDocument
    {
        $extension = strtolower($extension);

        return match ($extension) {
            'pdf' => $this->pdf->read($path, $name),
            'docx' => $this->docx->read($path, $name),
            'html', 'htm' => $this->fromHtml($path, $name),
            'csv', 'tsv' => $this->tables->read([['name' => '', 'rows' => $this->csvRows($path)]], $name, $currency),
            'xlsx' => $this->excel($path, $name, $currency),
            'xls' => throw new IngestionException("Ce format Excel ancien (.xls) n'est pas lu : ouvrez le fichier dans Excel, « Enregistrer sous » au format .xlsx ou CSV, puis renvoyez-le."),
            'txt', 'md' => new ExtractedDocument($name, Text::clean(file_get_contents($path))),
            default => throw new IngestionException("Format de fichier non pris en charge : .{$extension}"),
        };
    }

    private function fromHtml(string $path, string $name): ExtractedDocument
    {
        $page = $this->html->convert(file_get_contents($path));

        return new ExtractedDocument($page['title'] ?? $name, $page['text']);
    }

    private function excel(string $path, string $name, ?string $currency): ExtractedDocument
    {
        return $this->tables->read($this->xlsx->read($path), $name, $currency);
    }

    /**
     * Lignes d'un CSV : encodage deviné (UTF-8, sinon Windows-1252 comme l'Excel français), séparateur deviné (virgule,
     * point-virgule, tabulation, barre), cellules entre guillemets avec retours à la ligne comprises.
     *
     * @return list<list<string>>
     */
    private function csvRows(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $first = '';
        foreach (preg_split('/\R/u', $raw) as $line) {
            if (trim($line) !== '') {
                $first = $line;
                break;
            }
        }
        $delimiter = collect([',', ';', "\t", '|'])
            ->sortByDesc(fn ($d) => substr_count($first, $d))
            ->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($cell) => (string) $cell, $row);
            if (count($rows) > TableReader::MAX_PRODUCTS + 20) {
                break;
            }
        }
        fclose($stream);

        return $rows;
    }
}
