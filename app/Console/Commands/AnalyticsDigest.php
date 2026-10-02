<?php

namespace App\Console\Commands;

use App\Mail\AnalyticsDigest as DigestMail;
use App\Services\Analytics\ClientStats;
use App\Services\Analytics\Insights;
use App\Services\Analytics\Stats;
use App\Services\Analytics\Tracker;
use App\Services\PlatformSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Chaque lundi : le résumé de la semaine écoulée par e-mail (désactivable dans Paramètres, Statistiques). */
class AnalyticsDigest extends Command
{
    protected $signature = 'analytics:digest {--force : envoyer même si le résumé est désactivé}';

    protected $description = 'Envoie au super administrateur le résumé hebdomadaire des statistiques';

    public function handle(Tracker $tracker, PlatformSettings $settings, Insights $insights): int
    {
        if (! $this->option('force') && (! $tracker->enabled() || ! $settings->get('analytics.digest', true))) {
            $this->info('Résumé hebdomadaire désactivé.');

            return self::SUCCESS;
        }

        $to = $settings->alertEmail();
        if (! $to) {
            $this->warn('Aucune adresse de destination (PLATFORM_ADMIN_EMAIL ou e-mail de la marque).');

            return self::SUCCESS;
        }

        $stats = Stats::lastDays(7);
        $overview = $stats->overview();

        if ($overview['sessions'] === 0 && ! $this->option('force')) {
            $this->info('Aucune visite cette semaine : rien à envoyer.');

            return self::SUCCESS;
        }

        $kpis = collect($stats->kpis())->keyBy('key');
        $heat = $stats->heatmap();
        $clients = ClientStats::today(30);
        $active = $clients->active();
        $clickData = $stats->clicks(3);

        $data = [
            'brandName' => (string) $settings->get('brand.name', config('app.name')),
            'visits' => $overview['sessions'],
            'visitors' => $overview['visitors'],
            'pageviews' => $overview['pageviews'],
            'delta' => $kpis['sessions']['delta'],
            'bounce' => $overview['bounce_rate'],
            'peak' => $heat['peak'],
            'sources' => array_slice($stats->breakdown('source', 3), 0, 3),
            'pages' => array_slice($stats->pages(3), 0, 3),
            'cta' => $clickData['cta'][0] ?? null,
            'insights' => array_slice($insights->for($stats), 0, 4),
            'active' => $active,
            'risk' => $clients->atRisk(null, 3),
            'url' => route('admin.statistics.index'),
            'from' => $stats->from->locale('fr')->isoFormat('D MMMM'),
            'to' => $stats->to->locale('fr')->isoFormat('D MMMM YYYY'),
        ];

        try {
            Mail::to($to)->send(new DigestMail($data));
        } catch (\Throwable $e) {
            report($e);
            $this->error('Envoi impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Résumé envoyé.');

        return self::SUCCESS;
    }
}
