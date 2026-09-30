<?php

namespace App\Ai\Llm;

use App\Ai\LlmException;

/**
 * Point d'entree unique vers les modeles de langage. Il essaie les fournisseurs dans l'ordre de la chaine
 * (Claude, puis OpenAI, puis Llama par defaut) et bascule sur le suivant quand un fournisseur est
 * en panne, sans credit, sans droit ou surcharge. Sans aucune cle : mode hors ligne (demonstration).
 */
class LlmRouter implements LlmClient
{
    private ?FakeLlm $offline = null;

    public function __construct(private readonly ProviderRegistry $providers) {}

    public function name(): string
    {
        return 'router';
    }

    public function isOffline(): bool
    {
        return $this->providers->isOffline();
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        return $this->run(fn (LlmClient $client) => $client->complete($request), vision: false);
    }

    public function transcribe(string $binary, string $mimeType, string $instruction): string
    {
        return $this->run(fn (LlmClient $client) => $client->transcribe($binary, $mimeType, $instruction), vision: true);
    }

    private function run(callable $call, bool $vision): mixed
    {
        if ($this->providers->isOffline()) {
            $result = $call($this->offline ??= new FakeLlm);

            return $result instanceof LlmResponse ? $result->withProvider('offline') : $result;
        }

        $candidates = $this->providers->candidates($vision);
        $last = null;

        foreach ($candidates as $provider) {
            try {
                $result = $call($this->providers->client($provider));
                $this->providers->recordSuccess($provider, $result instanceof LlmResponse ? $result : null);

                return $result instanceof LlmResponse ? $result->withProvider($provider->name) : $result;
            } catch (LlmException $e) {
                $last = $e;
                $this->providers->recordFailure($provider, $e);

                if (! $e->shouldFailover()) {
                    throw $e;
                }
            }
        }

        throw $last ?? new LlmException(
            $vision ? "Aucun fournisseur capable de lire les images n'est disponible." : "Aucun fournisseur d'IA n'est disponible pour le moment.",
            retryable: true,
            kind: 'overloaded',
        );
    }
}
