<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsSession;
use App\Services\Analytics\ChatStats;
use App\Services\Analytics\ClientStats;
use App\Services\Analytics\Insights;
use App\Services\Analytics\Stats;
use App\Services\Analytics\Tracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** La page Statistiques de la plateforme (super administrateur) : visiteurs, comportements, clients. */
class StatisticsController extends Controller
{
    public const TABS = [
        'apercu' => 'Aperçu',
        'affluence' => 'Affluence',
        'pages' => 'Pages',
        'clics' => 'Clics',
        'acquisition' => 'Provenance',
        'parcours' => 'Parcours',
        'clients' => 'Clients',
        'experience' => 'Expérience',
        'chat' => 'Assistant du site',
    ];

    public const PERIODS = [7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '12 mois'];

    public const AUDIENCES = [
        AnalyticsSession::VISITOR => 'Visiteurs',
        AnalyticsSession::CLIENT => 'Clients connectés',
        'all' => 'Tous',
    ];

    public function index(Request $request, Tracker $tracker, Insights $insights)
    {
        $tab = array_key_exists($request->query('onglet'), self::TABS) ? $request->query('onglet') : 'apercu';
        $days = array_key_exists((int) $request->query('jours'), self::PERIODS) ? (int) $request->query('jours') : 30;
        $audience = array_key_exists($request->query('public'), self::AUDIENCES) ? $request->query('public') : AnalyticsSession::VISITOR;

        $stats = Stats::lastDays($days, $audience);

        // On ne calcule que ce que l'onglet affiche : la page reste rapide même avec beaucoup de visites.
        $data = match ($tab) {
            'apercu' => [
                'kpis' => $stats->kpis(),
                'daily' => $stats->daily(),
                'heat' => $stats->heatmap(),
                'insights' => $insights->for($stats),
                'live' => Stats::live($audience),
                'sources' => $stats->breakdown('source', 7),
                'pages' => array_slice($stats->pages(8), 0, 8),
            ],
            'affluence' => ['heat' => $stats->heatmap(), 'daily' => $stats->daily()],
            'pages' => ['pages' => $stats->pages(40)],
            'clics' => ['clicks' => $stats->clicks(20)],
            'acquisition' => [
                'sources' => $stats->breakdown('source', 10),
                'referrers' => $stats->breakdown('referrer_host', 12),
                'campaigns' => $stats->breakdown('utm_campaign', 10),
                'devices' => $stats->breakdown('device', 5),
                'browsers' => $stats->breakdown('browser', 8),
                'systems' => $stats->breakdown('os', 8),
                'countries' => $stats->breakdown('country', 12),
                'languages' => $stats->breakdown('language', 8),
                'overview' => $stats->overview(),
            ],
            'parcours' => ['funnel' => $stats->funnel(), 'journeys' => $stats->journeys(), 'entries' => $stats->breakdown('entry_path', 10)],
            'clients' => $this->clientData($insights, $days),
            'experience' => [
                'vitals' => $stats->vitals(),
                'scroll' => $stats->scrollDepth(),
                'forms' => $stats->forms(),
                'jsErrors' => $stats->errors(),
                'rage' => $stats->clicks(10)['rage'],
            ],
            'chat' => ['chat' => ChatStats::landing($stats)],
        };

        return view('admin.statistics.index', [
            'tab' => $tab,
            'days' => $days,
            'audience' => $audience,
            'stats' => $stats,
            'enabled' => $tracker->enabled(),
            'retention' => $tracker->retentionDays(),
            'tabs' => self::TABS,
            'periods' => self::PERIODS,
            'audiences' => self::AUDIENCES,
            'data' => $data,
            'timezone' => Tracker::timezone(),
        ]);
    }

    /** Les visites en cours, rafraîchies toutes les 20 secondes par la page. */
    public function live(Request $request): JsonResponse
    {
        $audience = array_key_exists($request->query('public'), self::AUDIENCES) ? $request->query('public') : 'all';

        return response()->json(Stats::live($audience));
    }

    /** Un tableau au format CSV (séparateur « ; » et BOM : Excel en français l'ouvre sans réglage). */
    public function export(Request $request, string $table): StreamedResponse
    {
        abort_unless(in_array($table, ['jours', 'pages', 'clics', 'provenance', 'clients'], true), 404);

        $days = array_key_exists((int) $request->query('jours'), self::PERIODS) ? (int) $request->query('jours') : 30;
        $audience = array_key_exists($request->query('public'), self::AUDIENCES) ? $request->query('public') : AnalyticsSession::VISITOR;
        $stats = Stats::lastDays($days, $audience);

        [$header, $rows] = match ($table) {
            'jours' => [['Date', 'Visites', 'Visiteurs', 'Pages vues'], array_map(fn ($d) => [$d['date'], $d['sessions'], $d['visitors'], $d['pageviews']], $stats->daily())],
            'pages' => [['Page', 'Chemin', 'Vues', 'Visiteurs', 'Entrées', 'Rebond %', 'Sorties %', 'Temps moyen (s)', 'Défilement 50 % (%)'], array_map(fn ($p) => [$p['name'], $p['path'], $p['views'], $p['visitors'], $p['entries'], $p['bounce_rate'], $p['exit_rate'], $p['seconds'], $p['scroll50']], $stats->pages(500))],
            'clics' => [['Élément', 'Page', 'Destination', 'Clics', 'Visiteurs'], array_map(fn ($c) => [$c['name'], $c['page'], $c['target'], $c['clicks'], $c['visitors']], $stats->clicks(500)['clicks'])],
            'provenance' => [['Source', 'Visites', 'Visiteurs', 'Rebond %', 'Temps moyen (s)', 'Aboutissent %'], array_map(fn ($s) => [$s['label'], $s['sessions'], $s['visitors'], $s['bounce_rate'], $s['avg_duration'], $s['conversion_rate']], $stats->breakdown('source', 50))],
            'clients' => [['Espace', 'Offre', 'Visites', 'Jours actifs', 'Fonctions utilisées', 'Dernière visite', 'Note de santé', 'Santé'], array_map(fn ($c) => [$c['name'], $c['plan'], $c['sessions'], $c['active_days'], $c['features'], $c['last_seen']?->format('Y-m-d'), $c['health']['score'], $c['health']['label']], ClientStats::today($days)->clients(300))],
        };

        $name = 'kouma-statistiques-'.$table.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows as $row) {
                // Une cellule qui commence par = + - @ serait lue comme une formule par le tableur : on la neutralise.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string,mixed> */
    private function clientData(Insights $insights, int $days): array
    {
        $clients = ClientStats::today($days);
        $rows = $clients->clients();

        return [
            'active' => $clients->active(),
            'daily' => $clients->dailyActive(),
            'rows' => $rows,
            'risk' => $clients->atRisk($rows),
            'adoption' => $clients->adoption(),
            'activation' => $clients->activation(),
            'cohorts' => $clients->cohorts(),
            'insights' => $insights->forClients($clients, $rows),
            'heat' => Stats::lastDays($days, AnalyticsSession::CLIENT)->heatmap(),
        ];
    }
}
