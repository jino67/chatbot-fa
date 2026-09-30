<?php

namespace App\Ingestion\Extractors;

use App\Ingestion\ExtractedDocument;
use App\Ingestion\IngestionException;
use App\Support\Text;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/** Lecture d'un .docx sans dependance : titres, listes et tableaux sont conserves en Markdown. */
class DocxReader
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const MAX_XML_BYTES = 30_000_000; // garde-fou contre les archives piegees

    public function read(string $path, string $fallbackTitle): ExtractedDocument
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new IngestionException("Ce fichier Word n'a pas pu être ouvert (fichier corrompu ou protégé).");
        }

        $stat = $zip->statName('word/document.xml');
        if (! $stat || $stat['size'] > self::MAX_XML_BYTES) {
            $zip->close();
            throw new IngestionException('Ce fichier Word est vide ou trop volumineux.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new IngestionException('Le contenu de ce fichier Word est illisible.');
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);
        $body = $xp->query('//w:body')->item(0);
        $lines = [];

        foreach ($body?->childNodes ?? [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($node->localName === 'p') {
                $lines[] = $this->paragraph($xp, $node);
            } elseif ($node->localName === 'tbl') {
                $lines[] = $this->table($xp, $node);
            }
        }

        $text = Text::clean(implode("\n\n", array_filter($lines, fn ($l) => trim($l) !== '')));

        return new ExtractedDocument($fallbackTitle, $text);
    }

    private function paragraph(DOMXPath $xp, DOMElement $p): string
    {
        $text = '';
        foreach ($xp->query('.//w:t|.//w:tab|.//w:br', $p) as $n) {
            $text .= match ($n->localName) {
                'tab' => ' ',
                'br' => "\n",
                default => $n->textContent,
            };
        }
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $style = $xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
        if (preg_match('/^(?:Heading|Titre)\s?(\d)$/i', (string) $style, $m)) {
            return str_repeat('#', min(6, (int) $m[1])).' '.$text;
        }
        if (strcasecmp((string) $style, 'Title') === 0 || strcasecmp((string) $style, 'Titre') === 0) {
            return '# '.$text;
        }
        if ($xp->query('w:pPr/w:numPr', $p)->length > 0) {
            return '- '.$text;
        }

        return $text;
    }

    private function table(DOMXPath $xp, DOMElement $table): string
    {
        $rows = [];
        foreach ($xp->query('.//w:tr', $table) as $tr) {
            $cells = [];
            foreach ($xp->query('./w:tc', $tr) as $tc) {
                $cellText = [];
                foreach ($xp->query('.//w:p', $tc) as $p) {
                    $cellText[] = trim($this->paragraph($xp, $p), "# -\t");
                }
                $cells[] = trim(implode(' ', array_filter($cellText)));
            }
            $rows[] = implode(' | ', $cells);
        }

        return implode("\n", array_filter($rows, fn ($r) => trim($r, ' |') !== ''));
    }
}
