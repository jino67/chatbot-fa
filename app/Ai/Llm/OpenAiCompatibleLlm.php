<?php

namespace App\Ai\Llm;

use App\Ai\LlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client pour toute API « chat completions » compatible OpenAI : OpenAI lui-meme, mais aussi les
 * hebergeurs de Llama (OpenRouter, DeepInfra, Together...) et les serveurs locaux (Ollama, vLLM).
 * Un seul adaptateur couvre donc OpenAI et Llama.
 */
class OpenAiCompatibleLlm implements LlmClient
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return (string) ($this->config['preset'] ?? 'openai');
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = $request->model ?: $this->config['model'];

        $messages = [['role' => 'system', 'content' => $request->system]];
        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message['role'], 'content' => $this->flatten($message['content'])];
        }

        return $this->post($model, $messages, $request->maxTokens ?: ($this->config['max_tokens'] ?? 900));
    }

    public function transcribe(string $binary, string $mimeType, string $instruction): string
    {
        if (empty($this->config['supports_vision']) || $mimeType === 'application/pdf') {
            throw new LlmException('Ce fournisseur ne lit pas les images ni les PDF scannés.', kind: 'unsupported');
        }

        $model = ($this->config['vision_model'] ?? null) ?: $this->config['model'];

        $response = $this->post($model, [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $instruction],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mimeType.';base64,'.base64_encode($binary)]],
            ],
        ]], 4000);

        return trim($response->text);
    }

    /** @param list<array<string,mixed>> $messages */
    private function post(string $model, array $messages, int $maxTokens): LlmResponse
    {
        if (! $this->keyless() && empty($this->config['api_key'])) {
            throw new LlmException('Clé manquante pour '.$this->name().'.', kind: 'auth');
        }

        // Les modeles recents d'OpenAI exigent max_completion_tokens ; les autres hebergeurs gardent max_tokens.
        $tokensKey = ($this->config['preset'] ?? '') === 'openai' ? 'max_completion_tokens' : 'max_tokens';

        try {
            $request = Http::timeout((int) ($this->config['timeout'] ?? 45))->acceptJson()->asJson();
            if (! empty($this->config['api_key'])) {
                $request = $request->withToken($this->config['api_key']);
            }
            $response = $request
                ->withHeaders(['X-Title' => config('brand.name'), 'HTTP-Referer' => config('app.url')])
                ->post(rtrim((string) $this->config['base_url'], '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    $tokensKey => $maxTokens,
                ]);
        } catch (ConnectionException $e) {
            throw new LlmException('Connexion à '.$this->name().' impossible : '.$e->getMessage(), retryable: true, previous: $e, kind: 'network');
        }

        if (! $response->successful()) {
            throw $this->error($response);
        }

        $choice = $response->json('choices.0', []);
        $text = $choice['message']['content'] ?? '';
        if (is_array($text)) {
            $text = implode('', array_map(fn ($part) => $part['text'] ?? '', $text));
        }

        return new LlmResponse(
            text: (string) $text,
            inputTokens: (int) $response->json('usage.prompt_tokens', 0),
            outputTokens: (int) $response->json('usage.completion_tokens', 0),
            cacheReadTokens: (int) $response->json('usage.prompt_tokens_details.cached_tokens', 0),
            stopReason: (string) ($choice['finish_reason'] ?? 'stop'),
            refused: ! empty($choice['message']['refusal']),
            model: $model,
            provider: $this->name(),
        );
    }

    private function error(Response $response): LlmException
    {
        $status = $response->status();
        $code = strtolower((string) ($response->json('error.code') ?? $response->json('error.type') ?? ''));
        $message = (string) ($response->json('error.message') ?? $response->body());
        $haystack = strtolower($code.' '.$message);
        $billing = (bool) preg_match('/insufficient_quota|insufficient credits|insufficient_funds|credit|quota|billing|balance/', $haystack);

        $kind = match (true) {
            $status === 402 => 'billing',
            in_array($status, [401, 403], true) => 'auth',
            $status === 429 && $billing => 'billing',   // OpenAI : 429 « insufficient_quota » = credit epuise, pas un simple ralentissement
            $status === 429 => 'rate_limit',
            $status === 408 || $status >= 500 => 'overloaded',
            $status === 404 => 'unsupported',
            $status === 400 && $billing => 'billing',
            default => 'bad_request',
        };

        return new LlmException(
            $this->name().' HTTP '.$status.' : '.mb_substr($message, 0, 220),
            retryable: in_array($kind, ['rate_limit', 'overloaded'], true),
            kind: $kind,
        );
    }

    private function keyless(): bool
    {
        return ($this->config['preset'] ?? '') === 'ollama';
    }

    /** Les blocs Anthropic (texte) sont aplatis en chaine ; les images ne transitent que par transcribe(). */
    private function flatten(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return implode("\n", array_map(fn ($block) => $block['text'] ?? '', $content));
    }
}
