<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lectures de la mesure d'audience sur une période : visites, affluence, pages, clics, provenance, parcours, entonnoirs,
 * vitesse et erreurs. Une instance = une période + un public (visiteurs, clients ou les deux ; l'équipe est toujours exclue).
 *
 * Les requêtes n'utilisent que du SQL portable (MySQL en production, SQLite en test) : le jour, l'heure et le jour de la
 * semaine sont déjà rangés dans des colonnes à l'écriture (voir Tracker).
 */
class Stats
{
    public const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public const SOURCES = [
        'direct' => 'Accès direct',
        'search' => 'Moteurs de recherche',
        'social' => 'Réseaux sociaux',
        'ai' => 'Assistants d\'IA',
        'email' => 'E-mails',
        'campaign' => 'Campagnes',
        'referral' => 'Autres sites',
    ];

    /** Seuils « bon » et « à améliorer » de chaque mesure de vitesse (en millisecondes ; la stabilité CLS est stockée × 1000). */
    public const VITALS = [
        'LCP' => ['label' => 'Affichage du contenu principal', 'good' => 2500, 'poor' => 4000, 'unit' => 'ms'],
        'FCP' => ['label' => 'Premier affichage', 'good' => 1800, 'poor' => 3000, 'unit' => 'ms'],
        'INP' => ['label' => 'Réactivité aux clics', 'good' => 200, 'poor' => 500, 'unit' => 'ms'],
        'CLS' => ['label' => 'Stabilité de la page', 'good' => 100, 'poor' => 250, 'unit' => ''],
        'TTFB' => ['label' => 'Réponse du serveur', 'good' => 800, 'poor' => 1800, 'unit' => 'ms'],
    ];

    /** Les pages connues, pour afficher un nom lisible à la place du chemin. */
    private const PAGE_NAMES = [
        '/' => 'Accueil', '/developpeurs' => 'Page développeurs', '/ressources' => 'Ressources', '/aide' => 'Aide et guides',
        '/aide/guide-client' => 'Guide du client', '/aide/guide-developpeur' => 'Guide du développeur', '/conditions' => 'Conditions',
        '/confidentialite' => 'Confidentialité', '/login' => 'Connexion', '/register' => 'Inscription', '/forgot-password' => 'Mot de passe oublié',
        '/dashboard' => 'Tableau de bord', '/bots' => 'Assistants', '/bots/create' => 'Création d\'un assistant', '/bots/:id/edit' => 'Réglages de l\'assistant',
        '/bots/:id/sources' => 'Connaissances', '/bots/:id/instructions' => 'Personnalité', '/bots/:id/playground' => 'Zone de test',
        '/bots/:id/conversations' => 'Conversations', '/bots/:id/conversations/:id' => 'Une conversation', '/bots/:id/analytics' => 'Analytique',
        '/bots/:id/channels' => 'Canaux', '/bots/:id/import' => 'Import WhatsApp', '/bots/:id/templates' => 'Modèles WhatsApp',
        '/demandes' => 'Demandes', '/alertes' => 'Alertes', '/notifications' => 'Notifications', '/notifications/preferences' => 'Préférences de notifications', '/billing' => 'Abonnement', '/profile' => 'Profil', '/developers/keys' => 'Clés d\'API',
    ];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $audience = AnalyticsSession::VISITOR,
    ) {}

    /** Les N derniers jours, aujourd'hui compris, dans le fuseau de la plateforme. */
    public static function lastDays(int $days, string $audience = AnalyticsSession::VISITOR): self
    {
        $to = CarbonImmutable::now(Tracker::timezone())->startOfDay();

        return new self($to->subDays(max(1, $days) - 1), $to, $audience);
    }

    public function days(): int
    {
        return (int) round($this->from->diffInDays($this->to, true)) + 1;
    }

    /** La période juste avant, de même durée : sert à dire « en hausse de 12 % ». */
    public function previous(): self
    {
        return new self($this->from->subDays($this->days()), $this->from->subDay(), $this->audience);
    }

    public function withAudience(string $audience): self
    {
        return new self($this->from, $this->to, $audience);
    }

    public static function pageName(?string $path): string
    {
        $path = (string) $path;
        if ($path === '') {
            return 'Inconnue';
        }

        if (isset(self::PAGE_NAMES[$path])) {
            return self::PAGE_NAMES[$path];
        }

        foreach (config('seo.pages', []) as $page) {
            if (isset($page['path']) && '/'.ltrim($page['path'], '/') === $path) {
                return (string) ($page['name'] ?? $page['title'] ?? $path);
            }
        }

        return $path;
    }

    public static function countryName(?string $code): string
    {
        return $code ? (config('analytics.countries.'.$code) ?? $code) : 'Inconnu';
    }

    /* ------------------------------------------------------------------------------------------------
       Requêtes de base
       ------------------------------------------------------------------------------------------------ */

    private function scope(Builder $query): Builder
    {
        $query->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()]);

        return $this->audience === 'all'
            ? $query->whereIn('audience', [AnalyticsSession::VISITOR, AnalyticsSession::CLIENT])
            : $query->where('audience', $this->audience);
    }

    private function sessions(): Builder
    {
        return $this->scope(DB::table('analytics_sessions'));
    }

    private function events(?string $type = null): Builder
    {
        $query = $this->scope(DB::table('analytics_events'));

        return $type ? $query->where('type', $type) : $query;
    }

    /** « La visite a fait X » : un test EXISTS sur les gestes de la visite (valeurs constantes, jamais de saisie). */
    private function has(string $condition): string
    {
        return "exists (select 1 from analytics_events e where e.session_id = analytics_sessions.id and {$condition})";
    }

    /** Une visite « réussie » : pour un visiteur, un contact ou une inscription ; pour un client, une action dans l'application. */
    private function converted(): string
    {
        return $this->audience === AnalyticsSession::CLIENT
            ? $this->has("e.type = 'action'")
            : $this->has("(e.type = 'contact' or (e.type = 'action' and e.name = 'registered'))");
    }

    private static function pct(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round(100 * $part / $whole, 1) : 0.0;
    }

    /* ------------------------------------------------------------------------------------------------
       Aperçu
       ------------------------------------------------------------------------------------------------ */

    /** @return array<string,int|float> */
    public function overview(): array
    {
        $row = $this->sessions()->selectRaw(
            'count(*) as sessions, count(distinct visitor_key) as visitors, '
            .'count(distinct case when is_new = 1 then visitor_key end) as new_visitors, '
            .'coalesce(sum(pageviews), 0) as pageviews, '
            .'coalesce(sum(case when duration_seconds > 0 then duration_seconds end), 0) as seconds, '
            .'coalesce(sum(case when duration_seconds > 0 then 1 else 0 end), 0) as timed, '
            .'coalesce(sum(case when is_bounce = 1 then 1 else 0 end), 0) as bounces, '
            .'coalesce(sum(case when is_pwa = 1 then 1 else 0 end), 0) as pwa, '
            .'coalesce(sum(case when '.$this->converted().' then 1 else 0 end), 0) as converted'
        )->first();

        $sessions = (int) $row->sessions;

        return [
            'sessions' => $sessions,
            'visitors' => (int) $row->visitors,
            'new_visitors' => (int) $row->new_visitors,
            'returning_visitors' => max(0, (int) $row->visitors - (int) $row->new_visitors),
            'pageviews' => (int) $row->pageviews,
            'pages_per_session' => $sessions > 0 ? round($row->pageviews / $sessions, 1) : 0.0,
            'avg_duration' => (int) $row->timed > 0 ? (int) round($row->seconds / $row->timed) : 0,
            'bounce_rate' => self::pct((int) $row->bounces, $sessions),
            'pwa_share' => self::pct((int) $row->pwa, $sessions),
            'conversions' => (int) $row->converted,
            'conversion_rate' => self::pct((int) $row->converted, $sessions),
        ];
    }

    /**
     * Les chiffres clés avec l'évolution face à la période précédente.
     *
     * @return list<array{key:string,label:string,value:int|float,format:string,previous:int|float,delta:?float,good_when:string}>
     */
    public function kpis(): array
    {
        $now = $this->overview();
        $before = $this->previous()->overview();

        $defs = [
            ['visitors', 'Visiteurs uniques', 'number', 'up'],
            ['sessions', 'Visites', 'number', 'up'],
            ['pageviews', 'Pages vues', 'number', 'up'],
            ['pages_per_session', 'Pages par visite', 'decimal', 'up'],
            ['avg_duration', 'Temps passé par visite', 'duration', 'up'],
            ['bounce_rate', 'Visites sans action (rebond)', 'percent', 'down'],
            ['returning_visitors', 'Visiteurs qui reviennent', 'number', 'up'],
            ['conversion_rate', $this->audience === AnalyticsSession::CLIENT ? 'Visites productives' : 'Visites qui aboutissent', 'percent', 'up'],
        ];

        return array_map(function (array $d) use ($now, $before) {
            [$key, $label, $format, $good] = $d;
            $previous = $before[$key] ?? 0;

            return [
                'key' => $key,
                'label' => $label,
                'value' => $now[$key],
                'format' => $format,
                'previous' => $previous,
                'delta' => $previous > 0 ? round(100 * ($now[$key] - $previous) / $previous, 1) : null,
                'good_when' => $good,
            ];
        }, $defs);
    }

    /** @return list<array{date:string,label:string,sessions:int,visitors:int,pageviews:int}> */
    public function daily(): array
    {
        $rows = $this->sessions()
            ->selectRaw('date, count(*) as sessions, count(distinct visitor_key) as visitors, coalesce(sum(pageviews), 0) as pageviews')
            ->groupBy('date')->get()
            ->keyBy(fn ($row) => substr((string) $row->date, 0, 10));

        $series = [];
        for ($day = $this->from; $day <= $this->to; $day = $day->addDay()) {
            $key = $day->toDateString();
            $row = $rows->get($key);
            $series[] = [
                'date' => $key,
                'label' => $day->locale('fr')->isoFormat('D MMM'),
                'sessions' => (int) ($row->sessions ?? 0),
                'visitors' => (int) ($row->visitors ?? 0),
                'pageviews' => (int) ($row->pageviews ?? 0),
            ];
        }

        return $series;
    }

    /* ------------------------------------------------------------------------------------------------
       Affluence : quand viennent-ils ?
       ------------------------------------------------------------------------------------------------ */

    /**
     * @return array{matrix:list<list<int>>, max:int, total:int, by_hour:list<int>, by_day:list<int>, peak:?array{day:string,hour:int,count:int}, peak_hour:?int, peak_day:?string}
     */
    public function heatmap(): array
    {
        $matrix = array_fill(0, 7, array_fill(0, 24, 0));

        foreach ($this->sessions()->selectRaw('dow, hour, count(*) as n')->groupBy('dow', 'hour')->get() as $row) {
            if ($row->dow >= 0 && $row->dow <= 6 && $row->hour >= 0 && $row->hour <= 23) {
                $matrix[(int) $row->dow][(int) $row->hour] = (int) $row->n;
            }
        }

        $byHour = array_map(null, ...$matrix) ? array_map('array_sum', array_map(null, ...$matrix)) : array_fill(0, 24, 0);
        $byDay = array_map('array_sum', $matrix);
        $total = array_sum($byDay);
        $peak = null;

        foreach ($matrix as $day => $hours) {
            foreach ($hours as $hour => $count) {
                if ($count > 0 && (! $peak || $count > $peak['count'])) {
                    $peak = ['day' => self::DAYS[$day], 'hour' => $hour, 'count' => $count];
                }
            }
        }

        return [
            'matrix' => $matrix,
            'max' => max(1, ...array_map('max', $matrix)),
            'total' => $total,
            'by_hour' => array_values($byHour),
            'by_day' => array_values($byDay),
            'peak' => $peak,
            'peak_hour' => $total > 0 ? array_search(max($byHour), $byHour, true) : null,
            'peak_day' => $total > 0 ? self::DAYS[array_search(max($byDay), $byDay, true)] : null,
        ];
    }

    /* ------------------------------------------------------------------------------------------------
       Pages
       ------------------------------------------------------------------------------------------------ */

    /** @return list<array<string,mixed>> */
    public function pages(int $limit = 20): array
    {
        $views = $this->events('pageview')->selectRaw('path, count(*) as views, count(distinct visitor_key) as visitors')
            ->groupBy('path')->orderByDesc('views')->limit($limit)->get();

        if ($views->isEmpty()) {
            return [];
        }

        $paths = $views->pluck('path')->all();

        $entries = $this->sessions()->whereIn('entry_path', $paths)
            ->selectRaw('entry_path as path, count(*) as entries, coalesce(sum(case when is_bounce = 1 then 1 else 0 end), 0) as bounces')
            ->groupBy('entry_path')->get()->keyBy('path');

        $exits = $this->sessions()->whereIn('exit_path', $paths)
            ->selectRaw('exit_path as path, count(*) as exits')->groupBy('exit_path')->get()->keyBy('path');

        $time = $this->events('engage')->whereIn('path', $paths)->where('value', '>', 0)
            ->selectRaw('path, avg(value) as seconds')->groupBy('path')->get()->keyBy('path');

        $scrolled = $this->events('scroll')->whereIn('path', $paths)->where('value', 50)
            ->selectRaw('path, count(distinct session_id) as n')->groupBy('path')->get()->keyBy('path');

        return $views->map(function ($row) use ($entries, $exits, $time, $scrolled) {
            $entry = $entries->get($row->path);
            $exit = $exits->get($row->path);

            return [
                'path' => $row->path,
                'name' => self::pageName($row->path),
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
                'entries' => (int) ($entry->entries ?? 0),
                'bounce_rate' => self::pct((int) ($entry->bounces ?? 0), (int) ($entry->entries ?? 0)),
                'exit_rate' => min(100.0, self::pct((int) ($exit->exits ?? 0), (int) $row->views)),
                'seconds' => (int) round($time->get($row->path)->seconds ?? 0),
                'scroll50' => min(100.0, self::pct((int) ($scrolled->get($row->path)->n ?? 0), (int) $row->visitors)),
            ];
        })->all();
    }

    /* ------------------------------------------------------------------------------------------------
       Clics : où cliquent-ils ?
       ------------------------------------------------------------------------------------------------ */

    /** @return array<string,list<array<string,mixed>>> */
    public function clicks(int $limit = 15): array
    {
        $rows = fn (string $type, bool $cta = false) => $this->events($type)
            ->when($cta, fn ($q) => $q->where('cta', 1))
            ->whereNotNull('name')
            ->selectRaw('name, path, max(target) as target, count(*) as clicks, count(distinct visitor_key) as visitors')
            ->groupBy('name', 'path')->orderByDesc('clicks')->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->name, 'path' => $r->path, 'page' => self::pageName($r->path), 'target' => $r->target, 'clicks' => (int) $r->clicks, 'visitors' => (int) $r->visitors])->all();

        $contacts = $this->events('contact')->selectRaw('name, count(*) as clicks, count(distinct visitor_key) as visitors')
            ->groupBy('name')->orderByDesc('clicks')->get()
            ->map(fn ($r) => ['name' => $r->name, 'clicks' => (int) $r->clicks, 'visitors' => (int) $r->visitors])->all();

        $outbound = $this->events('outbound')->whereNotNull('target')->selectRaw('target, count(*) as clicks, count(distinct visitor_key) as visitors')
            ->groupBy('target')->orderByDesc('clicks')->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->target, 'clicks' => (int) $r->clicks, 'visitors' => (int) $r->visitors])->all();

        $downloads = $this->events('click')->where('target', 'like', '%.pdf')->selectRaw('target, count(*) as clicks, count(distinct visitor_key) as visitors')
            ->groupBy('target')->orderByDesc('clicks')->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->target, 'clicks' => (int) $r->clicks, 'visitors' => (int) $r->visitors])->all();

        $rage = $this->events('rage')->selectRaw('name, path, count(*) as clicks, count(distinct visitor_key) as visitors')
            ->groupBy('name', 'path')->orderByDesc('clicks')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->name ?: '(zone sans nom)', 'path' => $r->path, 'page' => self::pageName($r->path), 'clicks' => (int) $r->clicks, 'visitors' => (int) $r->visitors])->all();

        // Les sections vues vont avec les clics : elles disent ce qui a été lu avant de cliquer.
        $sections = $this->events('view')->whereNotNull('name')->selectRaw('name, count(distinct session_id) as sessions')
            ->groupBy('name')->orderByDesc('sessions')->get()
            ->map(fn ($r) => ['name' => $r->name, 'sessions' => (int) $r->sessions])->all();

        return [
            'clicks' => $rows('click'),
            'cta' => $rows('click', true),
            'contacts' => $contacts,
            'outbound' => $outbound,
            'downloads' => $downloads,
            'rage' => $rage,
            'sections' => $sections,
        ];
    }

    /* ------------------------------------------------------------------------------------------------
       Provenance et public
       ------------------------------------------------------------------------------------------------ */

    /** @return list<array{label:string,key:string,sessions:int,visitors:int,bounce_rate:float,avg_duration:int,conversion_rate:float}> */
    public function breakdown(string $column, int $limit = 10): array
    {
        abort_unless(in_array($column, ['source', 'referrer_host', 'utm_campaign', 'device', 'browser', 'os', 'country', 'language', 'entry_path'], true), 500);

        return $this->sessions()->whereNotNull($column)
            ->selectRaw(
                "{$column} as label, count(*) as sessions, count(distinct visitor_key) as visitors, "
                .'coalesce(sum(case when is_bounce = 1 then 1 else 0 end), 0) as bounces, '
                .'coalesce(sum(case when duration_seconds > 0 then duration_seconds end), 0) as seconds, '
                .'coalesce(sum(case when duration_seconds > 0 then 1 else 0 end), 0) as timed, '
                .'coalesce(sum(case when '.$this->converted().' then 1 else 0 end), 0) as converted'
            )
            ->groupBy($column)->orderByDesc('sessions')->limit($limit)->get()
            ->map(fn ($r) => [
                'key' => (string) $r->label,
                'label' => match ($column) {
                    'source' => self::SOURCES[$r->label] ?? $r->label,
                    'country' => self::countryName($r->label),
                    'entry_path' => self::pageName($r->label),
                    'device' => ['mobile' => 'Téléphone', 'tablet' => 'Tablette', 'desktop' => 'Ordinateur'][$r->label] ?? $r->label,
                    default => (string) $r->label,
                },
                'sessions' => (int) $r->sessions,
                'visitors' => (int) $r->visitors,
                'bounce_rate' => self::pct((int) $r->bounces, (int) $r->sessions),
                'avg_duration' => (int) $r->timed > 0 ? (int) round($r->seconds / $r->timed) : 0,
                'conversion_rate' => self::pct((int) $r->converted, (int) $r->sessions),
            ])->all();
    }

    /* ------------------------------------------------------------------------------------------------
       Parcours
       ------------------------------------------------------------------------------------------------ */

    /**
     * Les passages d'une page à la suivante, calculés sur les 20 000 pages vues les plus récentes de la période.
     *
     * @return array{transitions:list<array{from:string,to:string,count:int}>, depth:array<string,int>, duration:array<string,int>}
     */
    public function journeys(): array
    {
        $views = $this->events('pageview')->whereNotNull('session_id')->orderByDesc('id')->limit(20000)->get(['id', 'session_id', 'path'])->sortBy('id');

        $last = [];
        $pairs = [];

        foreach ($views as $view) {
            $previous = $last[$view->session_id] ?? null;
            if ($previous !== null && $previous !== $view->path) {
                $key = $previous."\t".$view->path;
                $pairs[$key] = ($pairs[$key] ?? 0) + 1;
            }
            $last[$view->session_id] = $view->path;
        }

        arsort($pairs);
        $transitions = [];
        foreach (array_slice($pairs, 0, 12, true) as $key => $count) {
            [$from, $to] = explode("\t", $key);
            $transitions[] = ['from' => self::pageName($from), 'to' => self::pageName($to), 'count' => $count];
        }

        $depth = $this->sessions()->selectRaw(
            'coalesce(sum(case when pageviews <= 1 then 1 else 0 end), 0) as one, '
            .'coalesce(sum(case when pageviews = 2 then 1 else 0 end), 0) as two, '
            .'coalesce(sum(case when pageviews between 3 and 4 then 1 else 0 end), 0) as few, '
            .'coalesce(sum(case when pageviews >= 5 then 1 else 0 end), 0) as many'
        )->first();

        $time = $this->sessions()->where('duration_seconds', '>', 0)->selectRaw(
            'coalesce(sum(case when duration_seconds < 10 then 1 else 0 end), 0) as a, '
            .'coalesce(sum(case when duration_seconds between 10 and 29 then 1 else 0 end), 0) as b, '
            .'coalesce(sum(case when duration_seconds between 30 and 59 then 1 else 0 end), 0) as c, '
            .'coalesce(sum(case when duration_seconds between 60 and 179 then 1 else 0 end), 0) as d, '
            .'coalesce(sum(case when duration_seconds >= 180 then 1 else 0 end), 0) as e'
        )->first();

        return [
            'transitions' => $transitions,
            'depth' => ['1 page' => (int) $depth->one, '2 pages' => (int) $depth->two, '3 à 4 pages' => (int) $depth->few, '5 pages et plus' => (int) $depth->many],
            'duration' => ['moins de 10 s' => (int) $time->a, '10 à 29 s' => (int) $time->b, '30 à 59 s' => (int) $time->c, '1 à 3 min' => (int) $time->d, 'plus de 3 min' => (int) $time->e],
        ];
    }

    /**
     * L'entonnoir des visiteurs : de la visite à l'inscription, étape par étape.
     *
     * @return list<array{label:string,count:int,percent:float,from_previous:float}>
     */
    public function funnel(): array
    {
        $row = $this->sessions()->selectRaw(
            'count(*) as visits, '
            .'coalesce(sum(case when is_bounce = 0 then 1 else 0 end), 0) as engaged, '
            .'coalesce(sum(case when '.$this->has("e.type = 'view' and e.name = 'tarifs'").' then 1 else 0 end), 0) as pricing, '
            .'coalesce(sum(case when '.$this->has("e.type = 'click' and e.cta = 1").' then 1 else 0 end), 0) as cta, '
            .'coalesce(sum(case when '.$this->has("e.type = 'pageview' and e.path = '/register'").' then 1 else 0 end), 0) as form, '
            .'coalesce(sum(case when '.$this->has("e.type = 'action' and e.name = 'registered'").' then 1 else 0 end), 0) as registered'
        )->first();

        $steps = [
            ['Visites', (int) $row->visits],
            ['Ont lu, défilé ou cliqué', (int) $row->engaged],
            ['Ont regardé les offres', (int) $row->pricing],
            ['Ont cliqué sur un bouton d\'action', (int) $row->cta],
            ['Ont ouvert l\'inscription', (int) $row->form],
            ['Ont créé un compte', (int) $row->registered],
        ];

        $out = [];
        foreach ($steps as $i => [$label, $count]) {
            $out[] = [
                'label' => $label,
                'count' => $count,
                'percent' => self::pct($count, $steps[0][1]),
                'from_previous' => $i === 0 ? 100.0 : self::pct($count, $steps[$i - 1][1]),
            ];
        }

        return $out;
    }

    /* ------------------------------------------------------------------------------------------------
       Expérience : vitesse, défilement, formulaires, erreurs
       ------------------------------------------------------------------------------------------------ */

    /** @return list<array{key:string,label:string,p75:?float,samples:int,rating:string,display:string}> */
    public function vitals(): array
    {
        $out = [];

        foreach (self::VITALS as $key => $def) {
            $values = $this->events('vital')->where('name', $key)->whereNotNull('value')->orderByDesc('id')->limit(5000)->pluck('value')->map(fn ($v) => (int) $v)->sort()->values();
            $samples = $values->count();
            $p75 = $samples > 0 ? (float) $values[(int) min($samples - 1, ceil(0.75 * $samples) - 1)] : null;

            $out[] = [
                'key' => $key,
                'label' => $def['label'],
                'p75' => $p75,
                'samples' => $samples,
                'rating' => $p75 === null ? 'none' : ($p75 <= $def['good'] ? 'good' : ($p75 <= $def['poor'] ? 'needs' : 'poor')),
                'display' => $p75 === null ? 'Pas encore de mesure' : ($key === 'CLS' ? number_format($p75 / 1000, 2, ',', '') : ($p75 >= 1000 ? number_format($p75 / 1000, 1, ',', '').' s' : (int) $p75.' ms')),
            ];
        }

        return $out;
    }

    /** @return array<string,array{label:string,sessions:int,percent:float}> */
    public function scrollDepth(): array
    {
        $total = max(1, (int) $this->events('pageview')->distinct()->count('session_id'));
        $rows = $this->events('scroll')->whereIn('value', [25, 50, 75, 100])->selectRaw('value, count(distinct session_id) as n')->groupBy('value')->pluck('n', 'value');

        $out = [];
        foreach ([25, 50, 75, 100] as $mark) {
            $n = (int) ($rows[$mark] ?? 0);
            $out[$mark] = ['label' => $mark.' % de la page', 'sessions' => $n, 'percent' => min(100.0, self::pct($n, $total))];
        }

        return $out;
    }

    /** @return list<array{name:string,starts:int,submits:int,abandon:float}> */
    public function forms(): array
    {
        $rows = $this->events()->whereIn('type', ['form_start', 'form_submit'])->whereNotNull('name')
            ->selectRaw("name, sum(case when type = 'form_start' then 1 else 0 end) as starts, sum(case when type = 'form_submit' then 1 else 0 end) as submits")
            ->groupBy('name')->orderByDesc('starts')->limit(12)->get();

        return $rows->map(fn ($r) => [
            'name' => (string) $r->name,
            'starts' => (int) $r->starts,
            'submits' => (int) $r->submits,
            'abandon' => $r->starts > 0 ? max(0.0, round(100 * ($r->starts - $r->submits) / $r->starts, 1)) : 0.0,
        ])->all();
    }

    /** @return list<array{name:string,count:int,visitors:int,path:?string,last:?string}> */
    public function errors(): array
    {
        return $this->events('error')->whereNotNull('name')
            ->selectRaw('name, count(*) as n, count(distinct visitor_key) as visitors, max(path) as path, max(created_at) as last')
            ->groupBy('name')->orderByDesc('n')->limit(10)->get()
            ->map(fn ($r) => ['name' => (string) $r->name, 'count' => (int) $r->n, 'visitors' => (int) $r->visitors, 'path' => $r->path, 'last' => $r->last])->all();
    }

    /* ------------------------------------------------------------------------------------------------
       En direct
       ------------------------------------------------------------------------------------------------ */

    /**
     * Ce qui se passe maintenant : visites actives dans les 5 dernières minutes, pages regardées, derniers gestes.
     *
     * @return array{active:int, pages:list<array{name:string,count:int}>, recent:list<array{type:string,name:?string,page:string,ago:string}>}
     */
    public static function live(string $audience = 'all'): array
    {
        $scope = fn (Builder $q) => $audience === 'all' ? $q->whereIn('audience', [AnalyticsSession::VISITOR, AnalyticsSession::CLIENT]) : $q->where('audience', $audience);
        $since = now()->subMinutes(5);

        $active = $scope(DB::table('analytics_sessions'))->where('last_seen_at', '>=', $since)->count();

        $pages = $scope(DB::table('analytics_events'))->where('type', 'pageview')->where('created_at', '>=', $since)
            ->selectRaw('path, count(distinct visitor_key) as n')->groupBy('path')->orderByDesc('n')->limit(6)->get()
            ->map(fn ($r) => ['name' => self::pageName($r->path), 'count' => (int) $r->n])->all();

        $labels = ['pageview' => 'Page vue', 'click' => 'Clic', 'outbound' => 'Lien externe', 'contact' => 'Contact', 'form_start' => 'Début de formulaire', 'form_submit' => 'Formulaire envoyé', 'action' => 'Action', 'rage' => 'Clics répétés', 'error' => 'Erreur'];

        $recent = $scope(DB::table('analytics_events'))->whereIn('type', array_keys($labels))->orderByDesc('id')->limit(10)->get(['type', 'name', 'path', 'created_at'])
            ->map(fn ($r) => [
                'type' => $labels[$r->type] ?? $r->type,
                'name' => $r->name,
                'page' => self::pageName($r->path),
                'ago' => \Illuminate\Support\Carbon::parse($r->created_at)->locale('fr')->diffForHumans(),
            ])->all();

        return ['active' => $active, 'pages' => $pages, 'recent' => $recent];
    }
}
