<?php

namespace Tests\Support;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmRequest;
use App\Ai\Llm\LlmResponse;

/**
 * Modèle de langage scripté pour les tests : répond dans l'ordre aux appels (le dernier texte se répète), garde chaque
 * requête reçue et chaque photo lue, pour qu'un test puisse inspecter ce que l'assistant a vraiment vu.
 */
final class ScriptedLlm implements LlmClient
{
    /** @var list<LlmRequest> */
    public array $requests = [];

    public ?LlmRequest $last = null;

    /** @var list<array{mime:string, instruction:string, bytes:int}> */
    public array $photos = [];

    private int $turn = 0;

    /** @param list<string> $replies */
    public function __construct(private readonly array $replies = ['Bien reçu.'], private readonly string $vision = "CATEGORIE: autre\nRESUME: Une photo.") {}

    public function name(): string
    {
        return 'scripted';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $this->last = $request;
        $text = $this->replies[min($this->turn++, count($this->replies) - 1)];

        return new LlmResponse(text: $text, inputTokens: 10, outputTokens: 5, model: 'test', provider: 'scripted');
    }

    public function transcribe(string $binary, string $mimeType, string $instruction): string
    {
        $this->photos[] = ['mime' => $mimeType, 'instruction' => $instruction, 'bytes' => strlen($binary)];

        return $this->vision;
    }

    /** Dernier message de l'utilisateur envoyé au modèle (le tour courant avec ses extraits). */
    public function lastUserTurn(): string
    {
        $messages = $this->last?->messages ?? [];
        $content = $messages[array_key_last($messages)]['content'] ?? '';

        return is_array($content) ? implode("\n", array_map(fn ($b) => $b['text'] ?? '', $content)) : (string) $content;
    }
}
