<?php

namespace App\Console\Commands;

use App\Models\PushCampaign;
use App\Notify\CampaignSender;
use App\Notify\Notifier;
use Illuminate\Console\Command;

/**
 * Chaque minute (planificateur) : pousse les notifications retardées par les heures calmes, lance les campagnes programmées dont
 * l'heure est venue, et envoie un morceau des campagnes en cours. Tient dans le temps d'un passage de tâche planifiée.
 */
class DispatchNotifications extends Command
{
    protected $signature = 'notifications:dispatch';

    protected $description = 'Envoie les notifications différées et les campagnes programmées ou en cours';

    public function handle(Notifier $notifier, CampaignSender $sender): int
    {
        $deferred = $notifier->flushDeferred();

        foreach (PushCampaign::due()->orderBy('scheduled_at')->get() as $campaign) {
            $sender->start($campaign);
        }

        $budget = (int) config('notifications.push.budget_seconds', 40);
        $chunk = (int) config('notifications.push.chunk', 60);
        $deadline = microtime(true) + $budget;
        $processed = 0;

        // Le planificateur de l'hébergeur ne passe parfois que toutes les cinq minutes : chaque passage emploie tout son temps
        // (plusieurs morceaux de suite) plutôt que d'envoyer un seul morceau et d'attendre le suivant.
        foreach (PushCampaign::where('status', PushCampaign::SENDING)->orderBy('started_at')->get() as $campaign) {
            do {
                $left = (int) floor($deadline - microtime(true));
                if ($left <= 1) {
                    break 2;
                }

                $finished = $sender->process($campaign, $left, $chunk);
                $processed++;
            } while (! $finished);
        }

        $this->info("Notifications différées : {$deferred}. Campagnes traitées : {$processed}.");

        return self::SUCCESS;
    }
}
