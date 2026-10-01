<?php

namespace App\Console\Commands;

use App\Services\Analytics\Tracker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Efface les données de mesure plus vieilles que la durée de conservation (Paramètres, Statistiques ; 400 jours par défaut).
 * Garder moins, c'est exposer moins : ces données n'ont aucune valeur passé le point de comparaison d'une année sur l'autre.
 */
class AnalyticsPrune extends Command
{
    protected $signature = 'analytics:prune';

    protected $description = 'Supprime les données de mesure d\'audience plus anciennes que la durée de conservation';

    public function handle(Tracker $tracker): int
    {
        $limit = CarbonImmutable::now(Tracker::timezone())->subDays($tracker->retentionDays())->toDateString();

        $events = 0;
        do {
            $deleted = DB::table('analytics_events')->where('date', '<', $limit)->limit(5000)->delete();
            $events += $deleted;
        } while ($deleted === 5000);

        $sessions = 0;
        do {
            $deleted = DB::table('analytics_sessions')->where('date', '<', $limit)->limit(5000)->delete();
            $sessions += $deleted;
        } while ($deleted === 5000);

        $this->info("Mesure d'audience : {$sessions} visite(s) et {$events} geste(s) effacés (avant le {$limit}).");

        return self::SUCCESS;
    }
}
