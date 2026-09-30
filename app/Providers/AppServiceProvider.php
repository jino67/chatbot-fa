<?php

namespace App\Providers;

use App\Ai\Embeddings\EmbeddingClient;
use App\Ai\Embeddings\EmbeddingFactory;
use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmRouter;
use App\Ai\Llm\ProviderRegistry;
use App\Retrieval\HybridStore;
use App\Retrieval\SqlHybridStore;
use App\Services\PlatformSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Fournisseurs IA et embeddings : choisis depuis le tableau de bord (voir App\Ai).
     */
    public function register(): void
    {
        $this->app->singleton(PlatformSettings::class);
        $this->app->singleton(ProviderRegistry::class);

        // Le routeur essaie les fournisseurs dans l'ordre configure et bascule en cas de panne.
        $this->app->singleton(LlmClient::class, fn ($app) => new LlmRouter($app->make(ProviderRegistry::class)));

        // Non partage : un changement de moteur d'embeddings depuis le tableau de bord vaut aussi pour les workers deja lances.
        $this->app->bind(EmbeddingClient::class, fn ($app) => $app->make(EmbeddingFactory::class)->make());

        $this->app->singleton(HybridStore::class, SqlHybridStore::class);
    }

    public function boot(): void
    {
        // API publique du widget : limitee par IP, puis par assistant (protege la facture IA du client).
        RateLimiter::for('widget', fn (Request $request) => [
            Limit::perMinute((int) config('platform.widget.rate_per_minute_ip'))->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('platform.widget.rate_per_minute_bot'))->by('bot:'.$request->route('publicKey')),
        ]);

        // La marque (nom, accroche, contact) est disponible dans toutes les vues sous $brand.
        // Calculee une fois par requete (scoped) : un changement de marque n'est jamais fige dans un worker.
        $this->app->scoped('platform.brand', fn ($app) => $app->make(PlatformSettings::class)->brand());

        View::composer('*', fn ($view) => $view->with('brand', app('platform.brand')));
    }
}
