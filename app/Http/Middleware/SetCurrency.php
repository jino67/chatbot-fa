<?php

namespace App\Http\Middleware;

use App\Support\Currency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Choix de la devise d'affichage par le lien ?devise=EUR (sélecteur des pages de tarifs).
 * Mémorisé en session pour les visiteurs, et sur l'espace pour un client connecté.
 */
class SetCurrency
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = strtoupper((string) $request->query('devise'));

        if (Currency::isValid($code)) {
            Currency::remember($request, $code);
        }

        return $next($request);
    }
}
