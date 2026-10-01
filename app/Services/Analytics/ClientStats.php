<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSession;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ce que font les clients de la plateforme : qui est actif, qui décroche, quelles fonctions servent, comment les
 * nouveaux comptes avancent. Côté plateforme uniquement : un client ne voit jamais ces chiffres (voir TenantIsolationTest).
 */
class ClientStats
{
    public function __construct(
        public readonly CarbonImmutable $to,
        public readonly int $days = 30,
    ) {}

    public static function today(int $days = 30): self
    {
        return new self(CarbonImmutable::now(Tracker::timezone())->startOfDay(), $days);
    }

    private function from(): CarbonImmutable
    {
        return $this->to->subDays($this->days - 1);
    }

    private function clientSessions(?CarbonImmutable $from = null)
    {
        return DB::table('analytics_sessions')
            ->where('audience', AnalyticsSession::CLIENT)
            ->whereNotNull('workspace_id')
            ->whereBetween('date', [($from ?? $this->from())->toDateString(), $this->to->toDateString()]);
    }

    /* ------------------------------------------------------------------------------------------------
       Actifs : jour, semaine, mois
       ------------------------------------------------------------------------------------------------ */

    /** @return array{dau:int,wau:int,mau:int,stickiness:float,workspaces:int} */
    public function active(): array
    {
        $count = fn (int $days) => (int) $this->clientSessions($this->to->subDays($days - 1))->distinct()->count('workspace_id');

        $dau = $count(1);
        $wau = $count(7);
        $mau = $count(30);

        return [
            'dau' => $dau,
            'wau' => $wau,
            'mau' => $mau,
            'stickiness' => $mau > 0 ? round(100 * $dau / $mau, 1) : 0.0,
            'workspaces' => Workspace::count(),
        ];
    }

    /** Les espaces actifs jour après jour sur la période. @return list<array{label:string,count:int}> */
    public function dailyActive(): array
    {
        $rows = $this->clientSessions()->selectRaw('date, count(distinct workspace_id) as n')->groupBy('date')->pluck('n', 'date');

        $out = [];
        for ($day = $this->from(); $day <= $this->to; $day = $day->addDay()) {
            $out[] = ['label' => $day->locale('fr')->isoFormat('D MMM'), 'count' => (int) ($rows[$day->toDateString()] ?? 0)];
        }

        return $out;
    }

    /* ------------------------------------------------------------------------------------------------
       Santé de chaque client
       ------------------------------------------------------------------------------------------------ */

    /**
     * Note de 0 à 100 : revenir souvent et récemment, se servir de plusieurs fonctions, avoir de vrais clients qui écrivent.
     *
     * @return array{score:int,label:string,tone:string}
     */
    public static function health(?int $daysSince, int $activeDays, int $features, bool $live): array
    {
        $recency = match (true) {
            $daysSince === null => 0,
            $daysSince <= 2 => 40,
            $daysSince <= 6 => 30,
            $daysSince <= 13 => 15,
            $daysSince <= 29 => 5,
            default => 0,
        };

        $score = $recency + min(30, $activeDays * 5) + min(20, $features * 5) + ($live ? 10 : 0);

        return match (true) {
            $score >= 70 => ['score' => $score, 'label' => 'Très bonne', 'tone' => 'good'],
            $score >= 45 => ['score' => $score, 'label' => 'Correcte', 'tone' => 'ok'],
            $score >= 20 => ['score' => $score, 'label' => 'À surveiller', 'tone' => 'warn'],
            default => ['score' => $score, 'label' => 'En danger', 'tone' => 'bad'],
        };
    }

    /**
     * Un tableau par espace client : activité récente, fonctions utilisées, note de santé.
     *
     * @return list<array<string,mixed>>
     */
    public function clients(int $limit = 100): array
    {
        $sessions = $this->clientSessions()
            ->selectRaw('workspace_id, count(*) as sessions, count(distinct date) as active_days, coalesce(sum(pageviews), 0) as pageviews, max(last_seen_at) as last_seen')
            ->groupBy('workspace_id')->get()->keyBy('workspace_id');

        // Dernier passage, même hors de la période : un client silencieux depuis 40 jours doit apparaître comme tel.
        $lastEver = DB::table('analytics_sessions')->where('audience', AnalyticsSession::CLIENT)->whereNotNull('workspace_id')
            ->selectRaw('workspace_id, max(last_seen_at) as last_seen')->groupBy('workspace_id')->pluck('last_seen', 'workspace_id');

        $map = $this->featureMap();
        $used = [];
        DB::table('analytics_events')->where('audience', AnalyticsSession::CLIENT)->where('type', 'action')->whereNotNull('workspace_id')
            ->whereBetween('date', [$this->from()->toDateString(), $this->to->toDateString()])
            ->select('workspace_id', 'name')->distinct()->get()
            ->each(function ($row) use ($map, &$used) {
                if (isset($map[$row->name])) {
                    $used[$row->workspace_id][$map[$row->name]] = true;
                }
            });

        $live = DB::table('conversations')->where('channel', '!=', 'playground')->where('created_at', '>=', $this->from()->toDateTimeString())
            ->select('workspace_id')->distinct()->pluck('workspace_id')->flip();

        $rows = [];
        foreach (Workspace::orderByDesc('created_at')->limit(300)->get(['id', 'name', 'plan', 'created_at', 'is_suspended']) as $workspace) {
            $session = $sessions->get($workspace->id);
            $last = $lastEver->get($workspace->id) ? CarbonImmutable::parse($lastEver->get($workspace->id)) : null;
            $daysSince = $last ? (int) floor($last->diffInDays(CarbonImmutable::now(), true)) : null;
            $features = count($used[$workspace->id] ?? []);
            $health = self::health($daysSince, (int) ($session->active_days ?? 0), $features, $live->has($workspace->id));

            $rows[] = [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'plan' => $workspace->plan,
                'suspended' => (bool) $workspace->is_suspended,
                'created' => $workspace->created_at,
                'sessions' => (int) ($session->sessions ?? 0),
                'active_days' => (int) ($session->active_days ?? 0),
                'pageviews' => (int) ($session->pageviews ?? 0),
                'last_seen' => $last,
                'days_since' => $daysSince,
                'features' => $features,
                'live' => $live->has($workspace->id),
                'health' => $health,
            ];
        }

        usort($rows, fn ($a, $b) => [$b['health']['score'], $b['sessions']] <=> [$a['health']['score'], $a['sessions']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * L'activité d'un seul espace (fiche d'administration) : visites, jours actifs, pages et actions les plus fréquentes.
     *
     * @return array{sessions:int, active_days:int, seconds:int, last_seen:?CarbonImmutable, health:array{score:int,label:string,tone:string}, pages:list<array{name:string,count:int}>, actions:list<array{name:string,count:int}>, features:int}
     */
    public function workspace(int $workspaceId): array
    {
        $row = $this->clientSessions()->where('workspace_id', $workspaceId)
            ->selectRaw('count(*) as sessions, count(distinct date) as active_days, coalesce(sum(duration_seconds), 0) as seconds')->first();

        $last = DB::table('analytics_sessions')->where('audience', AnalyticsSession::CLIENT)->where('workspace_id', $workspaceId)->max('last_seen_at');
        $last = $last ? CarbonImmutable::parse($last) : null;

        $events = fn () => DB::table('analytics_events')->where('audience', AnalyticsSession::CLIENT)->where('workspace_id', $workspaceId)
            ->whereBetween('date', [$this->from()->toDateString(), $this->to->toDateString()]);

        $pages = $events()->where('type', 'pageview')->selectRaw('path, count(*) as n')->groupBy('path')->orderByDesc('n')->limit(6)->get()
            ->map(fn ($r) => ['name' => Stats::pageName($r->path), 'count' => (int) $r->n])->all();

        $actions = $events()->where('type', 'action')->selectRaw('name, count(*) as n')->groupBy('name')->orderByDesc('n')->limit(8)->get()
            ->map(fn ($r) => ['name' => (string) $r->name, 'count' => (int) $r->n])->all();

        $map = $this->featureMap();
        $features = count(array_unique(array_filter(array_map(fn ($a) => $map[$a['name']] ?? null, $actions))));
        $live = DB::table('conversations')->where('workspace_id', $workspaceId)->where('channel', '!=', 'playground')
            ->where('created_at', '>=', $this->from()->toDateTimeString())->exists();
        $daysSince = $last ? (int) floor($last->diffInDays(CarbonImmutable::now(), true)) : null;

        return [
            'sessions' => (int) $row->sessions,
            'active_days' => (int) $row->active_days,
            'seconds' => (int) $row->seconds,
            'last_seen' => $last,
            'health' => self::health($daysSince, (int) $row->active_days, $features, $live),
            'pages' => $pages,
            'actions' => $actions,
            'features' => $features,
        ];
    }

    /**
     * Les clients qui ont décroché : compte de plus de 7 jours, pas suspendu, sans visite depuis plus de 14 jours.
     *
     * @param  list<array<string,mixed>>|null  $clients
     * @return list<array<string,mixed>>
     */
    public function atRisk(?array $clients = null, int $limit = 10): array
    {
        $clients ??= $this->clients();

        $risk = array_values(array_filter($clients, fn ($c) => ! $c['suspended']
            && $c['created'] && $c['created']->lt(now()->subDays(7))
            && ($c['days_since'] === null || $c['days_since'] > 14)));

        usort($risk, fn ($a, $b) => ($b['days_since'] ?? 9999) <=> ($a['days_since'] ?? 9999));

        return array_slice($risk, 0, $limit);
    }

    /* ------------------------------------------------------------------------------------------------
       Fonctions utilisées
       ------------------------------------------------------------------------------------------------ */

    /** @return array<string,string> action => fonction */
    private function featureMap(): array
    {
        $map = [];
        foreach (config('analytics.features', []) as $key => $feature) {
            foreach ($feature['actions'] as $action) {
                $map[$action] = $key;
            }
        }

        return $map;
    }

    /** @return list<array{key:string,label:string,workspaces:int,percent:float}> */
    public function adoption(): array
    {
        $active = max(1, (int) $this->clientSessions()->distinct()->count('workspace_id'));
        $out = [];

        foreach (config('analytics.features', []) as $key => $feature) {
            $n = (int) DB::table('analytics_events')->where('audience', AnalyticsSession::CLIENT)->where('type', 'action')
                ->whereIn('name', $feature['actions'])->whereNotNull('workspace_id')
                ->whereBetween('date', [$this->from()->toDateString(), $this->to->toDateString()])
                ->distinct()->count('workspace_id');

            $out[] = ['key' => $key, 'label' => $feature['label'], 'workspaces' => $n, 'percent' => min(100.0, round(100 * $n / $active, 1))];
        }

        usort($out, fn ($a, $b) => $b['workspaces'] <=> $a['workspaces']);

        return $out;
    }

    /* ------------------------------------------------------------------------------------------------
       Mise en route des nouveaux comptes
       ------------------------------------------------------------------------------------------------ */

    /**
     * Les comptes créés sur la période, et jusqu'où ils sont allés. Calculé à partir des tables de l'application
     * (rien à instrumenter) : l'avancement est donc exact même pour les comptes d'avant la mesure.
     *
     * @return list<array{label:string,count:int,percent:float,from_previous:float}>
     */
    public function activation(): array
    {
        $from = $this->from()->startOfDay()->setTimezone(config('app.timezone'))->toDateTimeString();
        $to = $this->to->endOfDay()->setTimezone(config('app.timezone'))->toDateTimeString();

        $exists = fn (string $table, string $where = '1 = 1') => "exists (select 1 from {$table} t where t.workspace_id = workspaces.id and {$where})";

        $row = DB::table('workspaces')->whereBetween('created_at', [$from, $to])->selectRaw(
            'count(*) as created, '
            .'coalesce(sum(case when '.$exists('bots').' then 1 else 0 end), 0) as bot, '
            ."coalesce(sum(case when ".$exists('sources', "t.status = 'ready'").' then 1 else 0 end), 0) as knowledge, '
            ."coalesce(sum(case when ".$exists('conversations', "t.channel = 'playground'").' then 1 else 0 end), 0) as tested, '
            ."coalesce(sum(case when ".$exists('conversations', "t.channel != 'playground'")." or ".$exists('channels', "t.status = 'active'").' then 1 else 0 end), 0) as live, '
            ."coalesce(sum(case when plan != 'free' then 1 else 0 end), 0) as paying"
        )->first();

        $steps = [
            ['Comptes créés', (int) $row->created],
            ['Ont créé un assistant', (int) $row->bot],
            ['Ont donné des connaissances', (int) $row->knowledge],
            ['Ont testé leur assistant', (int) $row->tested],
            ['Sont en ligne avec de vrais clients', (int) $row->live],
            ['Ont pris une offre payante', (int) $row->paying],
        ];

        $out = [];
        foreach ($steps as $i => [$label, $count]) {
            $out[] = [
                'label' => $label,
                'count' => $count,
                'percent' => $steps[0][1] > 0 ? round(100 * $count / $steps[0][1], 1) : 0.0,
                'from_previous' => $i === 0 ? 100.0 : ($steps[$i - 1][1] > 0 ? round(100 * $count / $steps[$i - 1][1], 1) : 0.0),
            ];
        }

        return $out;
    }

    /* ------------------------------------------------------------------------------------------------
       Cohortes : les comptes d'une même semaine reviennent-ils ?
       ------------------------------------------------------------------------------------------------ */

    /**
     * @return list<array{week:string,size:int,retention:list<?float>}>
     */
    public function cohorts(int $weeks = 8): array
    {
        $thisWeek = $this->to->startOfWeek();
        $first = $thisWeek->subWeeks($weeks - 1);

        $workspaces = Workspace::where('created_at', '>=', $first->setTimezone(config('app.timezone')))->get(['id', 'created_at']);
        if ($workspaces->isEmpty()) {
            return [];
        }

        // Les semaines où chaque espace est venu au moins une fois.
        $seen = [];
        DB::table('analytics_sessions')->where('audience', AnalyticsSession::CLIENT)->whereIn('workspace_id', $workspaces->pluck('id'))
            ->where('date', '>=', $first->toDateString())->select('workspace_id', 'date')->distinct()->get()
            ->each(function ($row) use (&$seen, $first) {
                $week = (int) floor($first->diffInDays(CarbonImmutable::parse($row->date)->startOfDay(), false) / 7);
                $seen[$row->workspace_id][$week] = true;
            });

        $cohorts = [];
        foreach ($workspaces as $workspace) {
            $created = CarbonImmutable::parse($workspace->created_at)->setTimezone(Tracker::timezone());
            $index = (int) floor($first->diffInDays($created->startOfWeek(), false) / 7);
            if ($index >= 0 && $index < $weeks) {
                $cohorts[$index][] = $workspace->id;
            }
        }
        ksort($cohorts);

        $out = [];
        foreach ($cohorts as $index => $ids) {
            $retention = [];
            for ($offset = 0; $offset < $weeks; $offset++) {
                if ($index + $offset >= $weeks) {
                    $retention[] = null;

                    continue;
                }
                $returned = count(array_filter($ids, fn ($id) => ! empty($seen[$id][$index + $offset])));
                $retention[] = round(100 * $returned / count($ids), 1);
            }

            $out[] = ['week' => $first->addWeeks($index)->locale('fr')->isoFormat('D MMM'), 'size' => count($ids), 'retention' => $retention];
        }

        return $out;
    }
}
