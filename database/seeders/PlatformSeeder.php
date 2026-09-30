<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Amorcage de la plateforme, sans donnee de demonstration : garantit qu'un compte super admin existe.
 * Les offres et les fournisseurs d'IA sont deja crees par la migration.
 *
 * En production, definir PLATFORM_ADMIN_EMAIL (et idealement PLATFORM_ADMIN_PASSWORD) avant  php artisan migrate --seed.
 * En local, sans variable, le compte est admin@kouma.test.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('role', User::SUPER_ADMIN)->exists()) {
            return;
        }

        $email = config('platform.admin_email') ?: (app()->isProduction() ? null : 'admin@kouma.test');

        if (! $email) {
            $this->command?->warn('Aucun super admin : renseignez PLATFORM_ADMIN_EMAIL puis relancez le seeder, ou lancez  php artisan platform:make-admin adresse@exemple.com');

            return;
        }

        $password = config('platform.admin_password') ?: Str::password(16, symbols: false);

        $user = User::firstOrNew(['email' => Str::lower($email)]);
        $user->forceFill(['name' => config('platform.admin_name'), 'password' => $password, 'workspace_id' => null, 'is_active' => true]);
        $user->role = User::SUPER_ADMIN;
        $user->save();

        $this->command?->info("Compte super admin créé : {$user->email}");
        if (! config('platform.admin_password')) {
            $this->command?->line("Mot de passe généré : {$password}");
            $this->command?->warn('Notez-le maintenant : il ne sera plus affiché. Changez-le depuis votre profil.');
        }
    }
}
