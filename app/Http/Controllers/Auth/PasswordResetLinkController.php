<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Demande d'un lien de réinitialisation. La réponse est la même que l'adresse existe ou non : on ne révèle pas
     * quels comptes existent. Seule une demande trop rapprochée est signalée.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
        }

        return back()->with('status', 'Si un compte existe avec cette adresse, un lien de réinitialisation vient de lui être envoyé. Pensez à regarder dans les courriers indésirables.');
    }
}
