<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Currency;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

        // Chaque entreprise cliente dispose de son espace (workspace) : c'est l'unite d'isolation des donnees.
        // L'offre par defaut est gratuite et limitee dans le temps : l'essai demarre a l'inscription.
        $plan = Plan::default();

        $workspace = Workspace::create([
            'name' => $request->company,
            'plan' => $plan?->slug ?? 'free',
            'currency' => Currency::current(),
            'subscription_status' => $plan?->hasTrial() ? Workspace::TRIALING : Workspace::ACTIVE,
            'plan_started_at' => now(),
            'plan_ends_at' => $plan?->trialEndsAt(),
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'workspace_id' => $workspace->id,
        ]);

        event(new Registered($user));

        Auth::login($user);

        // Message de bienvenue (centre de notifications et e-mail) : il n'empêche jamais l'inscription d'aboutir.
        app(\App\Notify\Events::class)->welcome($user, $workspace);

        return redirect(route('dashboard', absolute: false));
    }
}
