<?php

namespace App\Ingestion\Extractors;

use App\Ingestion\IngestionException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/**
 * Lecture d'un classeur Excel (.xlsx) sans dépendance : feuilles visibles, textes partagés ou en ligne, nombres,
 * cellules vides entre deux colonnes. Les formules donnent leur dernière valeur calculée. Les images, graphiques
 * et mises en forme sont ignorés.
 */
class XlsxReader
{
    public const MAX_ROWS = 5000;

    /** Taille décompressée maximale d'une partie du classeur (protège contre les archives piégées). */
    private const MAX_PART_BYTES = 30 * 1024 * 1024;

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @return list<array{name:string, rows:list<list<string>>}> */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new IngestionException("Ce fichier Excel est illisible ou abîmé : enregistrez-le de nouveau au format .xlsx.");
        }

        try {
            $workbook = $this->part($zip, 'xl/workbook.xml');
            if ($workbook === null) {
                throw new IngestionException("Ce fichier n'est pas un classeur Excel (.xlsx) valide.");
            }

            $shared = $this->sharedStrings($zip);
            $targets = $this->relationships($zip);
            $sheets = [];

            $xml = $this->xml($workbook);
            foreach ($xml->sheets->sheet ?? [] as $sheet) {
                if (in_array((string) $sheet['state'], ['hidden', 'veryHidden'], true)) {
                    continue;
                }
                $id = (string) $sheet->attributes(self::NS_REL)['id'];
                $target = $targets[$id] ?? null;
                $data = $target ? $this->part($zip, $target) : null;
                if ($data === null) {
                    continue;
                }

                $rows = $this->rows($data, $shared);
                if ($rows !== []) {
                    $sheets[] = ['name' => trim((string) $sheet['name']), 'rows' => $rows];
                }
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    private function part(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_PART_BYTES) {
            throw new IngestionException('Une feuille de ce classeur est trop volumineuse : enregistrez les produits dans un fichier CSV.');
        }

        $data = $zip->getFromName($name);

        return $data === false ? null : $data;
    }

    private function xml(string $data): SimpleXMLElement
    {
        $xml = @simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($xml === false) {
            throw new IngestionException('Ce classeur Excel est abîmé : enregistrez-le de nouveau au format .xlsx.');
        }

        return $xml;
    }

    /** @return array<string,string> identifiant de relation => chemin dans l'archive */
    private function relationships(ZipArchive $zip): array
    {
        $data = $this->part($zip, 'xl/_rels/workbook.xml.rels');
        $targets = [];
        if ($data === null) {
            return $targets;
        }

        foreach ($this->xml($data)->Relationship ?? [] as $rel) {
            $target = (string) $rel['Target'];
            $targets[(string) $rel['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        }

        return $targets;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $data = $this->part($zip, 'xl/sharedStrings.xml');
        if ($data === null) {
            return [];
        }

        $strings = [];
        foreach ($this->xml($data)->si ?? [] as $si) {
            $strings[] = $this->richText($si);
        }

        return $strings;
    }

    /** Texte d'un <si> ou d'un <is> : soit <t>, soit des morceaux <r><t> (mise en forme partielle). */
    private function richText(SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }

        $text = '';
        foreach ($node->r ?? [] as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /**
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private function rows(string $data, array $shared): array
    {
        $reader = new XMLReader;
        $reader->XML($data, null, LIBXML_NONET | LIBXML_NOCDATA);

        $rows = [];
        $doc = new \DOMDocument;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
                continue;
            }

            $row = simplexml_import_dom($reader->expand($doc));
            $cells = [];
            $next = 0;

            foreach ($row->c ?? [] as $cell) {
                $index = isset($cell['r']) ? $this->columnIndex((string) $cell['r']) : $next;
                $cells[$index] = $this->value($cell, $shared);
                $next = $index + 1;
            }

            if ($cells === []) {
                continue;
            }

            $line = [];
            for ($i = 0; $i <= max(array_keys($cells)); $i++) {
                $line[] = $cells[$i] ?? '';
            }
            while ($line !== [] && end($line) === '') {
                array_pop($line);
            }
            if ($line !== []) {
                $rows[] = $line;
            }

            // L'en-tête compte pour une ligne : au-delà, on s'arrête (le lecteur signale la coupe).
            if (count($rows) > self::MAX_ROWS + 20) {
                break;
            }
        }

        $reader->close();

        return $rows;
    }

    /** @param list<string> $shared */
    private function value(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell['t'];

        return match ($type) {
            's' => $shared[(int) $cell->v] ?? '',
            'inlineStr' => isset($cell->is) ? $this->richText($cell->is) : '',
            'str' => (string) $cell->v,
            'b' => (string) $cell->v === '1' ? 'Oui' : 'Non',
            'e' => '',
            default => $this->number((string) $cell->v),
        };
    }

    /** « 18000 », « 1.8E4 », « 0.1 » : un nombre lisible, sans notation scientifique. */
    private function number(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return $raw;
        }

        $n = (float) $raw;
        if (abs($n) < 1e15 && $n == floor($n)) {
            return (string) (int) $n;
        }

        return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.');
    }

    /** « B3 » -> 1, « AA7 » -> 26. */
    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $m);
        $letters = strtoupper($m[0] ?? 'A');
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }
}
