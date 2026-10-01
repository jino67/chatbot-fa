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
use App\Services\UsageMeter;
use App\Services\UsageService;
use App\Speech\SpeechClient;
use App\Speech\SpeechFactory;
use App\Speech\VoiceService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        // Notifications sur les appareils : l'envoi réel passe par Web Push ; les tests le remplacent par un faux.
        $this->app->bind(\App\Push\PushGateway::class, \App\Push\WebPushGateway::class);
        $this->app->singleton(SpeechClient::class, fn ($app) => $app->make(SpeechFactory::class)->make());
        $this->app->singleton(VoiceService::class, fn ($app) => new VoiceService($app->make(SpeechFactory::class), $app->make(UsageService::class), $app->make(UsageMeter::class)));
    }

    public function boot(): void
    {
        // En production, les adresses absolues (canonique, plan du site, liens des e-mails, aperçus de partage) viennent
        // de APP_URL, jamais de l'en-tête Host de la requête : une adresse de secours ou un en-tête forgé n'entre pas dans
        // les pages que lisent les moteurs de recherche.
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        // API publique du widget : limitee par IP, puis par assistant (protege la facture IA du client).
        RateLimiter::for('widget', fn (Request $request) => [
            Limit::perMinute((int) config('platform.widget.rate_per_minute_ip'))->by('ip:'.$request->ip()),
            Limit::perMinute((int) config('platform.widget.rate_per_minute_bot'))->by('bot:'.$request->route('publicKey')),
        ]);

        // Mesure d'audience : 120 lots par minute et par adresse IP (l'adresse sert seulement à limiter le débit, jamais gardée).
        RateLimiter::for('analytics', fn (Request $request) => Limit::perMinute(120)->by('ip:'.$request->ip()));

        // API des developpeurs : 60 requetes par minute et par cle, plus un plafond par adresse IP.
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute(60)->by('key:'.substr(hash('sha256', (string) $request->bearerToken()), 0, 16)),
            Limit::perMinute(240)->by('ip:'.$request->ip()),
        ]);

        // La marque (nom, accroche, contact) est disponible dans toutes les vues sous $brand.
        // Calculee une fois par requete (scoped) : un changement de marque n'est jamais fige dans un worker.
        $this->app->scoped('platform.brand', fn ($app) => $app->make(PlatformSettings::class)->brand());

        View::composer('*', fn ($view) => $view->with('brand', app('platform.brand')));

        // E-mail de réinitialisation du mot de passe : en français, aux couleurs de la marque (la notification de
        // Laravel reste la même ; seul son contenu change).
        ResetPassword::toMailUsing(function ($user, string $token) {
            $brand = app(PlatformSettings::class)->brand()['name'];

            return (new MailMessage)
                ->subject("Réinitialisation de votre mot de passe {$brand}")
                ->view(['emails.password-reset', 'emails.password-reset-text'], [
                    'brandName' => $brand,
                    'name' => $user->name,
                    'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
                    'url' => route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]),
                ]);
        });
    }
}
