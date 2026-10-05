<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureWorkspaceContext;
use App\Http\Middleware\RecordActions;
use App\Http\Middleware\RedirectLegacyHost;
use App\Http\Middleware\ResolveWidgetBot;
use App\Http\Middleware\SetCurrency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'superadmin' => EnsureSuperAdmin::class,
            'staff' => EnsureStaff::class,
            'workspace' => EnsureWorkspaceContext::class,
            'widget.bot' => ResolveWidgetBot::class,
            'api.key' => AuthenticateApiKey::class,
        ]);

        // Après un changement de domaine : l'ancien nom redirige ses pages vers le nouveau (voir docs/DOMAINE.md).
        $middleware->prepend(RedirectLegacyHost::class);

        // Devise d'affichage choisie par le lien ?devise=EUR (pages de tarifs).
        $middleware->appendToGroup('web', SetCurrency::class);

        // Mesure des actions importantes (voir config/analytics.php, « actions »).
        $middleware->appendToGroup('web', RecordActions::class);

        // Les webhooks WhatsApp sont authentifies par signature, pas par session : pas de jeton CSRF. Le retour d'Apple (formulaire
        // POST venu d'apple.com) est reconnu par son état à usage unique et son cookie (voir SocialLogin::consume).
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'a/e', 'auth/apple/callback']);

        // Les identifiants de mesure d'audience sont aléatoires et lisibles par le serveur : ni chiffrés ni signés.
        $middleware->encryptCookies(except: ['_kv', '_ks', '_ko', 'kouma_oauth']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
