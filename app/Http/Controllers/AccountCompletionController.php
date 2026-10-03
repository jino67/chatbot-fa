<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * La dernière étape d'une inscription par Google, Apple, Microsoft ou Facebook : le fournisseur donne le nom et l'adresse,
 * il manque l'entreprise et le numéro WhatsApp. Tant que ce n'est pas fait, l'espace n'est pas utilisable (voir
 * EnsureWorkspaceContext), mais le compte existe déjà : l'équipe peut contacter la personne qui s'arrête ici.
 */
class AccountCompletionController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->needs_profile) {
            return redirect()->route('dashboard');
        }

        return view('account.complete', [
            'user' => $user,
            'workspace' => $user->workspace,
            // Pour un relais Apple (adresse masquée), on propose de renseigner l'adresse habituelle.
            'relay' => str_ends_with(mb_strtolower($user->email), '@privaterelay.appleid.com'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->needs_profile, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()\-\.]{7,25}$/'],
            'country' => ['nullable', 'string', 'max:60'],
            'contact_email' => ['nullable', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'terms' => ['accepted'],
        ], [
            'phone.regex' => 'Saisissez un numéro valide, avec l\'indicatif si possible (ex. +226 70 00 00 00).',
            'terms.accepted' => 'Acceptez les conditions d\'utilisation pour continuer.',
        ]);

        $user->forceFill(['name' => $data['name'], 'phone' => $data['phone'], 'needs_profile' => false]);
        if (filled($data['contact_email'] ?? null)) {
            $user->email = $data['contact_email'];
        }
        $user->save();

        $user->workspace?->update(['name' => $data['company'], 'phone' => $data['phone'], 'country' => $data['country'] ?? null]);

        return redirect()->route('dashboard')->with('status', 'Merci ! Votre espace est prêt. Créons votre premier assistant.');
    }
}
