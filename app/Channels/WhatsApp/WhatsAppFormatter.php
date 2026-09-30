<?php

namespace App\Channels\WhatsApp;

/** Adapte le texte d'une reponse au format WhatsApp et le decoupe si necessaire. */
final class WhatsAppFormatter
{
    /** Markdown -> conventions WhatsApp (*gras*, _italique_). */
    public static function format(string $text): string
    {
        // Titres : "## Titre" -> "*Titre*"
        $text = preg_replace('/^#{1,6}\s+(.+)$/m', '*$1*', $text);
        // Gras Markdown **x** -> *x*
        $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text);
        // Liens [texte](url) -> texte (url)
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '$1 ($2)', $text);
        // Puces "* item" -> "- item" (evite de les confondre avec du gras)
        $text = preg_replace('/^\*\s+/m', '- ', $text);

        return trim($text);
    }

    /**
     * Decoupe aux limites de paragraphe pour rester sous la limite du fournisseur (Twilio : 1600 caracteres).
     *
     * @return list<string>
     */
    public static function parts(string $text, int $max = 1500): array
    {
        $text = self::format($text);
        if (mb_strlen($text) <= $max) {
            return [$text];
        }

        $parts = [];
        $current = '';

        foreach (preg_split('/\n{2,}/u', $text) as $paragraph) {
            while (mb_strlen($paragraph) > $max) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
                $cut = mb_substr($paragraph, 0, $max);
                $space = mb_strrpos($cut, ' ') ?: $max;
                $parts[] = trim(mb_substr($paragraph, 0, $space));
                $paragraph = trim(mb_substr($paragraph, $space));
            }
            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > $max) {
                $parts[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n\n").$paragraph;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}
