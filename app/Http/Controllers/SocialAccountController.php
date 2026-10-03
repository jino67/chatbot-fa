<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Retirer la liaison d'un compte Google, Apple, Microsoft ou Facebook (depuis le profil). */
class SocialAccountController extends Controller
{
    public function destroy(Request $request, string $provider): RedirectResponse
    {
        $user = $request->user();
        $accounts = $user->socialAccounts()->get();
        $account = $accounts->firstWhere('provider', $provider);

        abort_unless($account, 404);

        // On ne laisse jamais une personne sans aucun moyen de se connecter.
        if (! $user->has_password && $accounts->count() <= 1) {
            return back()->with('error', 'Choisissez d\'abord un mot de passe : sans lui, ce compte '.ucfirst($provider).' est votre seul moyen de vous connecter.');
        }

        $account->delete();
        AuditLog::record('user.social_unlinked', $user->email, ['fournisseur' => $provider], $user->workspace_id);

        return redirect()->to(route('profile.edit').'#connexions')->with('status', 'social-unlinked');
    }
}
