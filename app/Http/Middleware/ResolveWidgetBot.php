<?php

namespace App\Http\Middleware;

use App\Models\Bot;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API publique du widget : la cle publique (pk_...) identifie l'assistant, l'en-tete Origin est compare
 * a la liste blanche du client. Attention : cette liste protege contre l'usage du widget depuis
 * un site tiers dans un navigateur ; contre les appels serveur a serveur, seuls le rate limiting
 * et les quotas d'offre protegent (voir docs/ARCHITECTURE.md, section securite).
 */
class ResolveWidgetBot
{
    public function handle(Request $request, Closure $next): Response
    {
        $bot = Bot::withoutGlobalScopes()
            ->with('workspace')
            ->where('public_key', $request->route('publicKey'))
            ->first();

        if (! $bot || ! $bot->is_active) {
            return response()->json(['error' => 'assistant_not_found'], 404);
        }

        if (! $bot->allowsOrigin($request->headers->get('Origin'))) {
            return response()->json(['error' => 'origin_not_allowed'], 403);
        }

        $request->attributes->set('bot', $bot);

        return $next($request);
    }
}
