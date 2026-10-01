<?php

namespace App\Import;

use App\Models\Bot;
use App\Support\Text;
use Carbon\Carbon;

/**
 * Transforme l'export d'une discussion WhatsApp en deux choses que le client valide avant tout enregistrement :
 *  - son style d'écriture (mesures et résumé) ;
 *  - des paires question / réponse (ce que ses clients demandent, ce qu'il répond d'habitude).
 * Tout est anonymisé ici ; le fichier lui-même n'est jamais conservé.
 */
class ChatImporter
{
    public const MAX_PAIRS = 60;

    public function __construct(
        private readonly WhatsAppChatParser $parser,
        private readonly StyleProfiler $styles,
    ) {}

    /**
     * Lit l'export et le pseudonymise : les noms des participants deviennent P1, P2..., les numéros, e-mails et liens
     * sont masqués. C'est la seule version qui est gardée (30 minutes au plus) pendant que le client choisit qui il est
     * et valide ; le fichier d'origine n'est jamais conservé.
     *
     * @return array{authors: array<string,array{name:string,count:int,samples:list<string>}>, messages: list<array{at:?string,author:string,text:string}>}
     */
    public function prepare(string $text): array
    {
        $parsed = $this->parser->parse($text);
        $names = array_keys($parsed['authors']);
        $anonymizer = new Anonymizer($names, '[nom]');

        $aliases = [];
        foreach ($names as $i => $name) {
            $aliases[$name] = 'P'.($i + 1);
        }

        $messages = [];
        $samples = [];
        foreach (array_slice($parsed['messages'], -6000) as $message) {
            $alias = $aliases[$message['author']];
            $clean = $anonymizer->clean($message['text']);
            $messages[] = ['at' => $message['at']?->toIso8601String(), 'author' => $alias, 'text' => $clean];

            if (mb_strlen($clean) >= 20) {
                $samples[$alias][] = Text::limit(str_replace("\n", ' ', $clean), 110);
            }
        }

        $authors = [];
        foreach ($parsed['authors'] as $name => $count) {
            $alias = $aliases[$name];
            $authors[$alias] = ['name' => $name, 'count' => $count, 'samples' => array_slice($samples[$alias] ?? [], -3)];
        }

        return ['authors' => $authors, 'messages' => $messages];
    }

    /**
     * @param  array{authors: array<string,mixed>, messages: list<array{at:?string,author:string,text:string}>}  $prepared
     * @return array{owner:string, messages:int, owner_messages:int, pairs:list<array{q:string,a:string}>, style:string, examples:list<string>, stats:array<string,mixed>, llm:bool}
     */
    public function analyze(array $prepared, string $ownerAlias, Bot $bot): array
    {
        $clean = fn (string $t) => trim(str_replace('[nom]', 'le client', $t));
        $messages = array_map(fn ($m) => $m + ['time' => $m['at'] ? Carbon::parse($m['at']) : null], $prepared['messages']);

        // Tours de parole : les messages consécutifs d'un même côté (à moins de 15 minutes) n'en font qu'un.
        $turns = [];
        foreach ($messages as $message) {
            $side = $message['author'] === $ownerAlias ? 'owner' : 'customer';
            $last = $turns[array_key_last($turns)] ?? null;
            $gap = $last && $last['end'] && $message['time'] ? $last['end']->diffInMinutes($message['time'], false) : 0;

            if ($last && $last['side'] === $side && $gap <= 15) {
                $turns[array_key_last($turns)]['text'] .= "\n".$message['text'];
                $turns[array_key_last($turns)]['end'] = $message['time'] ?? $last['end'];
            } else {
                $turns[] = ['side' => $side, 'text' => $message['text'], 'start' => $message['time'], 'end' => $message['time']];
            }
        }

        $pairs = [];
        $seen = [];
        for ($i = 0; $i < count($turns) - 1; $i++) {
            [$question, $reply] = [$turns[$i], $turns[$i + 1]];
            if ($question['side'] !== 'customer' || $reply['side'] !== 'owner') {
                continue;
            }
            if ($question['end'] && $reply['start'] && $question['end']->diffInHours($reply['start'], false) > 24) {
                continue;
            }

            $q = $clean($question['text']);
            $a = $clean($reply['text']);
            $key = mb_substr(Text::fold($q), 0, 60);

            if (isset($seen[$key]) || ! $this->usable($q, $a)) {
                continue;
            }

            $seen[$key] = true;
            $pairs[] = ['q' => Text::limit($q, 300), 'a' => Text::limit($a, 900)];
        }

        $ownerMessages = [];
        foreach ($messages as $message) {
            if ($message['author'] === $ownerAlias && mb_strlen($message['text']) >= 3) {
                $ownerMessages[] = $clean($message['text']);
            }
        }
        $ownerMessages = array_slice($ownerMessages, -400);

        $profile = $this->styles->profile($ownerMessages, $bot->workspace_id, $bot->id);

        return [
            'owner' => $ownerAlias,
            'messages' => count($messages),
            'owner_messages' => count($ownerMessages),
            'pairs' => array_slice($pairs, -self::MAX_PAIRS),
            'style' => $profile['style'],
            'examples' => $profile['examples'],
            'stats' => $profile['stats'],
            'llm' => $profile['llm'],
        ];
    }

    /** Une paire utile : une vraie question, une vraie réponse, pas noyée dans les données personnelles. */
    private function usable(string $question, string $answer): bool
    {
        if (mb_strlen($question) < 12 || mb_strlen($answer) < 8) {
            return false;
        }

        $q = Text::fold($question);
        if (preg_match('/^(?:bonjour|bonsoir|salut|merci|ok|d\'accord|oui|non|coucou|hello|allo)\b[\s.!?,]*(?:\w+\s*){0,2}[.!?]*$/u', $q)) {
            return false;
        }

        // Une réponse réduite à des émojis ou à des marques de politesse ne dit rien.
        if (! preg_match('/\p{L}{3,}/u', $answer)) {
            return false;
        }

        $masks = preg_match_all('/\[(?:numéro|e-mail|lien WhatsApp)\]/u', $question.' '.$answer);

        return $masks <= 2;
    }
}
