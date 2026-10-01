<?php

namespace App\Import;

use Carbon\Carbon;

/**
 * Lit l'export d'une discussion WhatsApp (« Exporter la discussion », sans les médias), Android ou iPhone, en français
 * ou en anglais :
 *   12/03/2025 14:32 - Fatou: Bonjour                 (Android)
 *   [12/03/2025, 14:32:05] Fatou: Bonjour             (iPhone)
 *   12/03/25, 2:32 PM - Fatou: Bonjour                (Android anglais)
 * Un message peut tenir sur plusieurs lignes. Les messages du système (chiffrement, ajout au groupe...), les médias
 * omis et les messages supprimés sont écartés.
 */
final class WhatsAppChatParser
{
    private const HEADER = '/^\[?(\d{1,4})[\/.\-](\d{1,2})[\/.\-](\d{1,4}),?\s+(\d{1,2})[:.hH](\d{2})(?:[:.](\d{2}))?\s*([AaPp]\.?[Mm]\.?)?\]?\s*(?:[-\x{2013}\x{2014}]\s*)?(.*)$/u';

    private const SYSTEM = '/chiffr|encrypt|a ajouté|a été ajouté|added|a quitté|left|a créé le groupe|created group|numéro de sécurité|security code|a changé l|changed the|vous avez été ajouté|you were added|appel (?:vocal|vidéo)? ?manqué|missed (?:voice|video) call/iu';

    private const MEDIA = '/^(?:<\s*(?:médias?|media)\s+(?:omis|omitted)\s*>|(?:image|vidéo|video|audio|sticker|gif|document|contact)\s+(?:omis|omise|absente?|omitted)|\(fichier joint\)|.*\(file attached\)|ce message a été supprimé|message supprimé|you deleted this message|this message was deleted|null)\.?$/iu';

    /**
     * @return array{messages: list<array{at:?Carbon,author:string,text:string}>, authors: array<string,int>}
     */
    public function parse(string $raw): array
    {
        $raw = preg_replace('/^\x{FEFF}/u', '', $raw);
        $raw = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $raw);
        $raw = str_replace(["\u{202F}", "\u{00A0}"], ' ', $raw);

        $messages = [];
        $current = null;

        foreach (preg_split('/\R/u', $raw) as $line) {
            if (preg_match(self::HEADER, $line, $m)) {
                $this->flush($messages, $current);
                $current = null;

                $rest = $m[8];
                if (preg_match('/^([^:]{1,40}?):\s(.*)$/u', $rest, $parts) && str_word_count($parts[1]) <= 6 && ! preg_match(self::SYSTEM, $parts[1])) {
                    $current = ['at' => $this->date($m), 'author' => trim($parts[1]), 'text' => $parts[2]];
                }
                // Sinon : message du système, écarté (et il n'a pas de suite).

                continue;
            }

            // Suite d'un message sur plusieurs lignes.
            if ($current !== null) {
                $current['text'] .= "\n".$line;
            }
        }
        $this->flush($messages, $current);

        $authors = [];
        foreach ($messages as $message) {
            $authors[$message['author']] = ($authors[$message['author']] ?? 0) + 1;
        }
        arsort($authors);

        return ['messages' => $messages, 'authors' => $authors];
    }

    /** @param  list<array{at:?Carbon,author:string,text:string}>  $messages */
    private function flush(array &$messages, ?array $current): void
    {
        if ($current === null) {
            return;
        }

        $text = trim($current['text']);
        if ($text === '' || preg_match(self::MEDIA, $text)) {
            return;
        }

        $current['text'] = $text;
        $messages[] = $current;
    }

    /** @param  array<int,string>  $m */
    private function date(array $m): ?Carbon
    {
        [$a, $b, $c] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $hour = (int) $m[4];
        $meridiem = strtolower(str_replace('.', '', $m[7] ?? ''));
        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        // Année en tête (2025-03-12), sinon jour d'abord (usage francophone) ; si le « mois » dépasse 12, on inverse.
        if ($a > 31) {
            [$year, $month, $day] = [$a, $b, $c];
        } else {
            [$day, $month, $year] = [$a, $b, $c < 100 ? 2000 + $c : $c];
            if ($month > 12) {
                [$day, $month] = [$month, $day];
            }
        }

        try {
            return Carbon::create($year, $month, $day, $hour, (int) $m[5], (int) ($m[6] ?? 0));
        } catch (\Throwable) {
            return null;
        }
    }
}
