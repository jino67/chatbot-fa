<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie l'API des developpeurs par cle secrete (« Authorization: Bearer kma_... »). Chaque erreur a un code
 * stable et un message clair : un developpeur doit comprendre seul ce qui ne va pas.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        if ($token === '') {
            return $this->error('missing_key', "Ajoutez l'en-tête « Authorization: Bearer kma_... » avec votre clé d'API.", 401);
        }

        $key = ApiKey::findActive($token);
        if (! $key) {
            return $this->error('invalid_key', 'Clé d\'API inconnue ou révoquée.', 401);
        }

        $workspace = $key->workspace;
        if (! $workspace || ! $workspace->hasFeature('api')) {
            return $this->error('plan_required', "L'offre de cet espace n'inclut pas l'API. Choisissez l'offre API Développeur.", 403);
        }
        if ($workspace->is_suspended || $workspace->trialExpired()) {
            return $this->error('account_suspended', 'Cet espace est suspendu ou son essai est terminé.', 403);
        }

        // La date de derniere utilisation ne s'ecrit qu'une fois par minute : pas une ecriture par requete.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('bot', $key->bot);
        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
