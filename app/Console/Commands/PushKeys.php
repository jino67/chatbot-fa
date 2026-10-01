<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Push\Vapid;
use Illuminate\Console\Command;

/**
 * État des clés des notifications (VAPID). La clé privée n'est jamais affichée. Les clés se créent toutes seules à la première
 * utilisation : cette commande sert à vérifier qu'elles existent, ou à les remplacer en cas de fuite.
 */
class PushKeys extends Command
{
    protected $signature = 'push:keys {--renew : remplacer les clés (tous les appareils devront se réabonner)}';

    protected $description = 'Affiche l\'état des clés des notifications, ou les remplace avec --renew';

    public function handle(Vapid $vapid): int
    {
        if ($this->option('renew')) {
            if (! $this->confirm('Remplacer les clés ? Chaque appareil abonné devra se réabonner (cela se fait tout seul à sa prochaine ouverture de l\'application).')) {
                return self::SUCCESS;
            }

            $public = $vapid->generate();
            $this->info('Nouvelles clés créées. Clé publique : '.$public);

            return self::SUCCESS;
        }

        if (! $vapid->exists()) {
            $this->warn('Aucune clé pour le moment : elles seront créées à la première utilisation (première page vue par un utilisateur connecté).');
            $vapid->ensure();
        }

        $this->info('Clés des notifications : présentes. La clé privée est chiffrée et n\'est jamais affichée.');
        $this->line('Clé publique : '.$vapid->publicKey());
        $this->line('Appareils abonnés : '.PushSubscription::count());

        return self::SUCCESS;
    }
}
