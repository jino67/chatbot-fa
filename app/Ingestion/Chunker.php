<?php

namespace App\Ingestion;

use App\Support\Text;

/**
 * Decoupage structure : on respecte les titres Markdown (le fil d'Ariane est conserve avec
 * chaque extrait) puis les paragraphes, avec un leger chevauchement a l'interieur d'une meme section.
 */
class Chunker
{
    private int $size;

    private int $overlap;

    public function __construct(?int $size = null, ?int $overlap = null)
    {
        $this->size = $size ?? (int) config('platform.rag.chunk_chars');
        $this->overlap = $overlap ?? (int) config('platform.rag.overlap_chars');
    }

    /**
     * @return list<array{heading:?string, content:string, position:int}>
     */
    public function split(string $text): array
    {
        $chunks = [];
        $buffer = '';
        $heading = null;
        $hasNew = false; // le tampon contient-il autre chose que le chevauchement ?
        $tail = '';

        $flush = function () use (&$chunks, &$buffer, &$hasNew, &$tail, &$heading) {
            $content = trim($buffer);
            if ($hasNew && $content !== '') {
                $chunks[] = ['heading' => $heading, 'content' => $content, 'position' => count($chunks)];
                $tail = $this->tail($content);
            }
            $buffer = '';
            $hasNew = false;
        };

        foreach ($this->sections(Text::clean($text)) as $section) {
            $first = true;

            foreach ($this->paragraphs($section['body']) as $paragraph) {
                if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($paragraph) + 2 > $this->size) {
                    $hadNew = $hasNew;
                    $flush();
                    // Chevauchement uniquement a l'interieur d'une meme section.
                    $buffer = ($first || $tail === '' || ! $hadNew) ? '' : $tail."\n\n";
                } elseif ($buffer !== '' && $first && $section['heading']) {
                    // Petite section fusionnee avec la precedente : son titre reste dans le texte.
                    $buffer .= "\n\n".$section['heading'];
                }

                if ($buffer === '' || ! $hasNew) {
                    $heading = $section['heading'];
                }

                $buffer .= ($buffer === '' ? '' : "\n\n").$paragraph;
                $hasNew = true;
                $first = false;
            }
        }

        $flush();

        return $chunks;
    }

    /** @return list<array{heading:?string, body:string}> */
    private function sections(string $text): array
    {
        $sections = [];
        $stack = [];
        $current = ['heading' => null, 'lines' => []];

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $line, $m)) {
                $sections[] = $current;
                $level = strlen($m[1]);
                $stack = array_slice($stack, 0, $level - 1);
                $stack[$level - 1] = $m[2];
                $current = ['heading' => implode(' > ', array_filter($stack)), 'lines' => []];
            } else {
                $current['lines'][] = $line;
            }
        }
        $sections[] = $current;

        return array_values(array_filter(array_map(
            fn ($s) => ['heading' => $s['heading'], 'body' => trim(implode("\n", $s['lines']))],
            $sections
        ), fn ($s) => $s['body'] !== ''));
    }

    /** @return list<string> paragraphes ne depassant jamais la taille maximale */
    private function paragraphs(string $body): array
    {
        $out = [];

        foreach (preg_split('/\n{2,}/u', $body, -1, PREG_SPLIT_NO_EMPTY) as $paragraph) {
            $paragraph = trim($paragraph);
            if (mb_strlen($paragraph) <= $this->size) {
                $out[] = $paragraph;

                continue;
            }

            // Paragraphe trop long : phrases, puis coupe dure en dernier recours.
            $current = '';
            foreach (preg_split('/(?<=[.!?;])\s+|\n/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
                while (mb_strlen($sentence) > $this->size) {
                    if ($current !== '') {
                        $out[] = trim($current);
                        $current = '';
                    }
                    $out[] = mb_substr($sentence, 0, $this->size);
                    $sentence = mb_substr($sentence, $this->size);
                }
                if ($current !== '' && mb_strlen($current) + mb_strlen($sentence) + 1 > $this->size) {
                    $out[] = trim($current);
                    $current = '';
                }
                $current .= ($current === '' ? '' : ' ').$sentence;
            }
            if (trim($current) !== '') {
                $out[] = trim($current);
            }
        }

        return $out;
    }

    private function tail(string $content): string
    {
        if ($this->overlap <= 0 || mb_strlen($content) <= $this->overlap) {
            return '';
        }

        $tail = mb_substr($content, -$this->overlap);
        $space = mb_strpos($tail, ' ');

        return $space === false ? $tail : trim(mb_substr($tail, $space));
    }
}
