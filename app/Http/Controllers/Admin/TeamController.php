<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Comptes du personnel (admin et super admin). Reserve au super admin. */
class TeamController extends Controller
{
    public function index()
    {
        return view('admin.team', ['members' => User::whereIn('role', [User::SUPER_ADMIN, User::ADMIN])->orderBy('role')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in([User::ADMIN, User::SUPER_ADMIN])],
        ]);

        $password = Str::password(12, symbols: false);
        $user = new User(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $password]);
        $user->role = $data['role'];
        $user->save();

        AuditLog::record('team.created', $user->email, ['role' => $user->role]);

        return back()->with('status', 'Compte créé.')->with('new_password', ['email' => $user->email, 'password' => $password]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isStaff(), 404);

        $data = $request->validate(['role' => ['required', Rule::in([User::ADMIN, User::SUPER_ADMIN])]]);

        // On ne peut pas se retrograder soi-meme, ni retirer le dernier super admin.
        if ($user->id === $request->user()->id && $data['role'] !== User::SUPER_ADMIN) {
            return back()->with('error', 'Vous ne pouvez pas retirer votre propre rôle de super admin.');
        }

        $user->role = $data['role'];
        $user->save();
        AuditLog::record('team.role_changed', $user->email, ['role' => $user->role]);

        return back()->with('status', 'Rôle mis à jour.');
    }
}
