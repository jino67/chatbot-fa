<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Espace du personnel : admin et super admin. Un compte desactive est deconnecte. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->isStaff(), 403, "Espace réservé à l'équipe.");

        if (! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Ce compte a été désactivé.']);
        }

        return $next($request);
    }
}
