<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountRegistrar;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        [$user, $workspace] = app(AccountRegistrar::class)->register([
            'name' => $request->name,
            'company' => $request->company,
            'email' => $request->email,
            'password' => $request->password,
        ]);

        event(new Registered($user));

        Auth::login($user);

        // Message de bienvenue (centre de notifications et e-mail) : il n'empêche jamais l'inscription d'aboutir.
        app(\App\Notify\Events::class)->welcome($user, $workspace);

        return redirect(route('dashboard', absolute: false));
    }
}
