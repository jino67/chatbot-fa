<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crée un compte client et son espace : le même chemin pour l'inscription par e-mail et par un fournisseur externe (Google,
 * Apple...). Chaque entreprise cliente a son espace, l'unité d'isolation des données ; l'offre par défaut est gratuite et
 * limitée dans le temps : l'essai démarre à l'inscription.
 */
class AccountRegistrar
{
    /**
     * @param  array{name:string, company:string, email:string, password?:?string, phone?:?string, country?:?string, verified?:bool, needs_profile?:bool}  $data
     * @return array{0:User, 1:Workspace}
     */
    public function register(array $data, string $source = 'email'): array
    {
        $plan = Plan::default();

        return DB::transaction(function () use ($data, $source, $plan) {
            $workspace = Workspace::create([
                'name' => $data['company'],
                'plan' => $plan?->slug ?? 'free',
                'currency' => Currency::current(),
                'subscription_status' => $plan?->hasTrial() ? Workspace::TRIALING : Workspace::ACTIVE,
                'plan_started_at' => now(),
                'plan_ends_at' => $plan?->trialEndsAt(),
                'country' => $data['country'] ?? null,
                'phone' => $data['phone'] ?? null,
            ]);

            $hasPassword = filled($data['password'] ?? null);

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // Sans mot de passe choisi (connexion externe), un mot de passe aléatoire que personne ne connaît : la colonne est obligatoire.
                'password' => Hash::make($hasPassword ? $data['password'] : Str::random(48)),
                'workspace_id' => $workspace->id,
            ]);
            $user->forceFill([
                'has_password' => $hasPassword,
                'needs_profile' => (bool) ($data['needs_profile'] ?? false),
                'signup_source' => $source,
                'email_verified_at' => ($data['verified'] ?? false) ? now() : null,
            ])->save();

            return [$user, $workspace];
        });
    }
}
