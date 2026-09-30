<?php

namespace App\Ai\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Ai\LlmException;

/**
 * Client Claude via le SDK officiel PHP (POST /v1/messages).
 * Les pannes sont classees (credit epuise, cle refusee, surcharge...) pour piloter la bascule.
 */
class AnthropicLlm implements LlmClient
{
    private ?Client $client = null;

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $model = $request->model ?: $this->config['model'];

        $params = [
            'model' => $model,
            'maxTokens' => $request->maxTokens ?: ($this->config['max_tokens'] ?? 900),
            // Le prompt systeme d'un assistant est stable d'un message a l'autre : on le marque cachable.
            // En dessous du minimum du modele, le cache est simplement ignore par l'API.
            'system' => [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]],
            'messages' => $request->messages,
        ];

        if ($this->supportsEffort($model) && ! empty($this->config['effort'])) {
            $params['outputConfig'] = ['effort' => $this->config['effort']];
        }

        return $this->send($params, $model);
    }

    public function transcribe(string $binary, string $mimeType, string $instruction): string
    {
        $isPdf = $mimeType === 'application/pdf';

        $block = $isPdf
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($binary)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mimeType, 'data' => base64_encode($binary)]];

        $model = ($this->config['vision_model'] ?? null) ?: $this->config['model'];

        $response = $this->send([
            'model' => $model,
            'maxTokens' => 8000,
            'messages' => [[
                'role' => 'user',
                'content' => [$block, ['type' => 'text', 'text' => $instruction]],
            ]],
        ], $model);

        if ($response->refused) {
            throw new LlmException('Le modèle a refusé de lire ce fichier.', kind: 'bad_request');
        }

        return trim($response->text);
    }

    /** @param array<string,mixed> $params */
    private function send(array $params, string $model): LlmResponse
    {
        try {
            $message = $this->client()->messages->create(...$params);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new LlmException('Clé Anthropic refusée : '.$this->summary($e), previous: $e, kind: 'auth');
        } catch (RateLimitException $e) {
            throw new LlmException('Limite de débit Anthropic atteinte.', retryable: true, previous: $e, kind: 'rate_limit');
        } catch (InternalServerException $e) {
            throw new LlmException('Anthropic est surchargé ou indisponible (HTTP '.$e->status.').', retryable: true, previous: $e, kind: 'overloaded');
        } catch (APIConnectionException $e) {
            throw new LlmException('Connexion à Anthropic impossible : '.$e->getMessage(), retryable: true, previous: $e, kind: 'network');
        } catch (APIStatusException $e) {
            throw new LlmException('Requête Anthropic refusée : '.$this->summary($e), previous: $e, kind: $this->classifyStatus($e));
        } catch (AnthropicException $e) {
            throw new LlmException('Erreur du SDK Anthropic : '.$e->getMessage(), previous: $e);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new LlmResponse(
            text: $text,
            inputTokens: $message->usage->inputTokens ?? 0,
            outputTokens: $message->usage->outputTokens ?? 0,
            cacheReadTokens: $message->usage->cacheReadInputTokens ?? 0,
            stopReason: (string) ($message->stopReason ?? 'end_turn'),
            refused: ($message->stopReason ?? null) === 'refusal',
            model: $model,
            provider: 'anthropic',
        );
    }

    /**
     * Anthropic renvoie un credit epuise sous la forme d'une erreur 400 « credit balance is too low »
     * (et non d'un 402) : c'est le message, pas le code, qui le distingue d'une vraie requete invalide.
     */
    private function classifyStatus(APIStatusException $e): string
    {
        $text = strtolower($this->summary($e));

        return match (true) {
            $e->status === 402, (bool) preg_match('/credit balance|purchase credits|billing|plans & billing/', $text) => 'billing',
            $e->status === 404 => 'unsupported', // modele inconnu ou retire
            default => 'bad_request',
        };
    }

    private function summary(APIStatusException $e): string
    {
        $body = $e->body;
        $message = is_array($body) ? ($body['error']['message'] ?? null) : null;

        return (string) ($message ?: 'HTTP '.$e->status);
    }

    /** Le parametre `effort` est rejete par Haiku 4.5 et les modeles plus anciens. */
    private function supportsEffort(string $model): bool
    {
        return (bool) preg_match('/^claude-(opus-(5|4-[678])|sonnet-(5|4-6)|fable-5|mythos-5)/', $model);
    }

    private function client(): Client
    {
        if (empty($this->config['api_key'])) {
            throw new LlmException('Clé Anthropic manquante.', kind: 'auth');
        }

        return $this->client ??= new Client(apiKey: $this->config['api_key']);
    }
}
