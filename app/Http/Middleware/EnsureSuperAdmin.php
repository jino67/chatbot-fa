<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reserve les reglages sensibles (offres, fournisseurs IA, comptes du personnel, parametres) au super admin. */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Réservé au super admin.');

        return $next($request);
    }
}
