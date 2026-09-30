<?php

namespace App\Ai\Llm;

use App\Ai\LlmException;
use App\Support\Text;

/**
 * LLM hors ligne : repond en extrayant les phrases les plus proches de la question
 * dans les extraits fournis. Permet de demontrer et tester toute la chaine
 * (ingestion, recherche, conversation, WhatsApp) sans cle d'API ni reseau.
 */
class FakeLlm implements LlmClient
{
    public function name(): string
    {
        return 'fake';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $last = $request->messages[array_key_last($request->messages)] ?? [];
        $content = is_array($last['content'] ?? null)
            ? implode("\n", array_map(fn ($b) => $b['text'] ?? '', $last['content']))
            : (string) ($last['content'] ?? '');

        $question = $this->between($content, 'Question du visiteur :', null) ?? $content;
        $question = trim($question);

        preg_match_all('#<extrait[^>]*>(.*?)</extrait>#s', $content, $matches);
        $extraits = $matches[1] ?? [];

        $answer = $this->answer($question, $extraits);

        return new LlmResponse(
            text: $answer,
            inputTokens: Text::estimateTokens($request->system.$content),
            outputTokens: Text::estimateTokens($answer),
            model: 'fake',
        );
    }

    public function transcribe(string $binary, string $mimeType, string $instruction): string
    {
        throw new LlmException('La lecture des photos et des PDF scannés demande une clé ANTHROPIC_API_KEY (mode hors ligne actif).');
    }

    /** @param list<string> $extraits */
    private function answer(string $question, array $extraits): string
    {
        if ($extraits === []) {
            return Text::isSmallTalk($question)
                ? 'Bonjour ! Comment puis-je vous aider ?'
                : '[[NO_ANSWER]]';
        }

        $wanted = array_unique(Text::tokens($question));
        $scored = [];

        foreach ($extraits as $i => $extrait) {
            $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', trim($extrait), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($sentences as $j => $sentence) {
                $overlap = count(array_intersect($wanted, Text::tokens($sentence)));
                if ($overlap > 0) {
                    $scored[] = ['score' => $overlap, 'order' => $i * 1000 + $j, 'text' => trim($sentence)];
                }
            }
        }

        if ($scored === []) {
            return '[[NO_ANSWER]]';
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);
        $best = array_slice($scored, 0, 2);
        usort($best, fn ($a, $b) => $a['order'] <=> $b['order']);

        return implode(' ', array_column($best, 'text'));
    }

    private function between(string $haystack, string $start, ?string $end): ?string
    {
        $pos = strpos($haystack, $start);
        if ($pos === false) {
            return null;
        }
        $from = $pos + strlen($start);
        $to = $end ? strpos($haystack, $end, $from) : false;

        return $to === false ? substr($haystack, $from) : substr($haystack, $from, $to - $from);
    }
}
