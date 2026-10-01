<?php

namespace App\Console\Commands;

use App\Services\LandingBot;
use Illuminate\Console\Command;

/**
 * Crée ou met à jour l'assistant de la page d'accueil à partir des offres et des réglages actuels. À lancer après
 * la mise en ligne (il n'existe pas encore en production) et chaque fois que la grille des offres change hors de l'admin.
 */
class SyncLandingBot extends Command
{
    protected $signature = 'platform:landing-bot';

    protected $description = "Crée ou met à jour l'assistant de la page d'accueil (prix, langues, contact) d'après les offres actuelles";

    public function handle(LandingBot $landing): int
    {
        $bot = $landing->sync();
        $sources = $bot->sources()->count();
        $failed = $bot->sources()->where('status', 'failed')->count();

        $this->info("Assistant « {$bot->name} » à jour : {$sources} sources, clé publique {$bot->public_key}.");

        if ($failed > 0) {
            $this->warn("{$failed} source(s) en échec : vérifiez le fournisseur d'embeddings (page Administration, IA), puis relancez la commande.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
