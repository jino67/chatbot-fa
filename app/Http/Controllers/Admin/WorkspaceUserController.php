<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Comptes des utilisateurs d'un espace client : ajout, reinitialisation du mot de passe, desactivation. */
class WorkspaceUserController extends Controller
{
    public function store(Request $request, Workspace $workspace, UsageService $usage): RedirectResponse
    {
        if (! $usage->canAddUser($workspace)) {
            return back()->with('error', "La limite d'utilisateurs de l'offre {$workspace->planModel()?->name} est atteinte.");
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
        ]);

        $password = Str::password(12, symbols: false);
        User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $password, 'workspace_id' => $workspace->id]);

        AuditLog::record('user.created', $data['email'], [], $workspace->id);

        return back()->with('status', 'Utilisateur ajouté.')
            ->with('new_password', ['email' => Str::lower($data['email']), 'password' => $password]);
    }

    /** Nouveau mot de passe provisoire, affiche une seule fois. Le personnel peut aussi reinitialiser ses collegues (super admin seulement). */
    public function reset(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);

        $password = Str::password(12, symbols: false);
        $user->forceFill(['password' => $password])->save();

        AuditLog::record('user.password_reset', $user->email, [], $user->workspace_id);

        return back()->with('status', 'Mot de passe réinitialisé.')
            ->with('new_password', ['email' => $user->email, 'password' => $password]);
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);
        abort_if($user->id === $request->user()->id, 422, 'Vous ne pouvez pas désactiver votre propre compte.');

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        AuditLog::record($user->is_active ? 'user.enabled' : 'user.disabled', $user->email, [], $user->workspace_id);

        return back()->with('status', $user->is_active ? 'Compte réactivé.' : 'Compte désactivé : la personne ne peut plus se connecter.');
    }

    /** Un admin gere les clients ; seuls les super admins gerent le personnel. */
    private function authorizeTarget(Request $request, User $user): void
    {
        abort_if($user->isStaff() && ! $request->user()->isSuperAdmin(), 403, 'Seul le super admin gère les comptes du personnel.');
    }
}
