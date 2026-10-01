<?php

namespace App\Http\Middleware;

use App\Support\Analytics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enregistre les actions importantes (créer un assistant, ajouter une source, répondre à un client...) sans toucher aux
 * contrôleurs : une table « nom de route => action » dans config/analytics.php. Seules les requêtes qui ont réussi comptent :
 * une validation refusée ou une erreur affichée à la personne n'est pas une action faite.
 */
class RecordActions
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || $response->getStatusCode() >= 400) {
            return $response;
        }

        $route = $request->route();
        $name = $route?->getName() ?? $request->method().' '.$request->path();
        // Les noms de route contiennent des points : on lit le tableau tel quel, pas par chemin « a.b.c ».
        $action = (config('analytics.actions') ?? [])[$name] ?? null;

        if (! $action) {
            return $response;
        }

        // Une redirection qui porte une erreur de saisie ou un message d'échec n'a rien accompli.
        if ($request->hasSession() && ($request->session()->has('errors') || $request->session()->has('error'))) {
            return $response;
        }

        Analytics::action($action);

        return $response;
    }
}
