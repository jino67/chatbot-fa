<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    protected $signature = 'platform:make-admin {email : Adresse e-mail} {--role=super_admin : super_admin ou admin} {--name= : Nom affiché} {--password= : Mot de passe (généré si omis)}';

    protected $description = 'Crée (ou promeut) un compte du personnel : super admin (tout) ou admin (gère les espaces clients)';

    public function handle(): int
    {
        $role = $this->option('role');
        if (! in_array($role, [User::SUPER_ADMIN, User::ADMIN], true)) {
            $this->error('Le rôle doit être super_admin ou admin.');

            return self::FAILURE;
        }

        $email = Str::lower($this->argument('email'));
        $user = User::where('email', $email)->first();
        $password = $this->option('password') ?: Str::password(16, symbols: false);

        if (! $user) {
            $user = new User(['name' => $this->option('name') ?: 'Équipe', 'email' => $email, 'password' => $password]);
        } elseif ($this->option('password')) {
            $user->password = $password;
        }

        $user->role = $role;
        $user->workspace_id = null;
        $user->is_active = true;
        $user->save();

        $this->info("Compte {$role} : {$email}");
        if (! $this->option('password') && $user->wasRecentlyCreated) {
            $this->line("Mot de passe généré : {$password}");
            $this->warn('Notez-le maintenant : il ne sera plus affiché.');
        }

        return self::SUCCESS;
    }
}
