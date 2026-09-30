<?php

namespace App\Ai\Embeddings;

use App\Services\PlatformSettings;
use Illuminate\Support\Facades\Log;

/**
 * Choisit le moteur d'embeddings : parametres du tableau de bord d'abord, environnement ensuite.
 * Sans cle valide, on retombe sur les vecteurs locaux plutot que de faire echouer la plateforme.
 * Il n'y a volontairement PAS de bascule automatique entre fournisseurs d'embeddings : des vecteurs
 * de modeles differents ne sont pas comparables ; un changement impose un recalcul (platform:reindex).
 */
class EmbeddingFactory
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function make(): EmbeddingClient
    {
        $config = config('platform.ai');
        $driver = $this->settings->get('embeddings.driver') ?: $config['embeddings'];
        $key = $this->settings->get('embeddings.api_key');
        $model = $this->settings->get('embeddings.model');

        return match ($driver) {
            'voyage' => $this->keyed('voyage', $key ?: $config['voyage']['api_key'], fn ($k) => new VoyageEmbeddings(
                $k, $model ?: $config['voyage']['model'], $config['voyage']['base_url']
            )),
            'openai' => $this->keyed('openai', $key ?: $config['openai']['api_key'], fn ($k) => new OpenAiEmbeddings(
                $k, $model ?: $config['openai']['model'], $config['openai']['base_url']
            )),
            default => new HashingEmbeddings,
        };
    }

    private function keyed(string $driver, ?string $key, callable $build): EmbeddingClient
    {
        if (! $key) {
            Log::warning("Embeddings « {$driver} » sélectionnés sans clé : repli sur les vecteurs locaux.");

            return new HashingEmbeddings;
        }

        return $build($key);
    }
}
