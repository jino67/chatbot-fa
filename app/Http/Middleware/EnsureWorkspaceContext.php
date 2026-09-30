<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages « espace client » (assistants, sources, conversations...). Elles ont besoin d'un espace :
 *  - un client a le sien ;
 *  - le personnel doit d'abord « entrer » dans l'espace qu'il veut gerer ;
 *  - un espace suspendu n'est plus utilisable par le client (sauf l'abonnement et le profil).
 */
class EnsureWorkspaceContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Ce compte a été désactivé.']);
        }

        if ($user->isStaff()) {
            if (! $user->currentWorkspace()) {
                // Apres la connexion, le personnel arrive sur son propre tableau de bord.
                return $request->routeIs('dashboard')
                    ? redirect()->route('admin.overview')
                    : redirect()->route('admin.workspaces.index')->with('error', "Choisissez d'abord l'espace client à gérer.");
            }

            return $next($request);
        }

        abort_unless($user->workspace_id, 403, "Ce compte n'est rattaché à aucun espace.");

        if ($user->workspace?->is_suspended && ! $request->routeIs('billing.*', 'profile.*', 'logout')) {
            return response()->view('account.suspended', ['workspace' => $user->workspace], 403);
        }

        return $next($request);
    }
}
