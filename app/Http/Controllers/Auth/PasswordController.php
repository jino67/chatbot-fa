<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Arrivée par Google, Apple... : il n'y a pas de mot de passe actuel à confirmer, on en définit un premier.
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => $user->has_password ? ['required', 'current_password'] : ['nullable'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user->forceFill(['password' => Hash::make($validated['password']), 'has_password' => true])->save();

        return back()->with('status', 'password-updated');
    }
}
