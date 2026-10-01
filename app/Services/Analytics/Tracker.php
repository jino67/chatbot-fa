<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\User;
use App\Services\PlatformSettings;
use App\Support\UserAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Collecteur de la mesure d'audience : reçoit les lots d'événements du navigateur (et les actions de l'application),
 * les nettoie et les enregistre.
 *
 * Vie privée : aucune adresse IP n'est lue ni gardée ; les textes (libellés de boutons, messages d'erreur) sont purgés
 * des adresses e-mail et des numéros ; seul l'identifiant aléatoire du navigateur permet de compter les visiteurs
 * uniques. Le signal « Ne pas suivre » (DNT), « Global Privacy Control » et le refus du visiteur (cookie _ko) sont respectés.
 */
class Tracker
{
    /** Les seuls types d'événements acceptés du navigateur. */
    public const BROWSER_TYPES = ['pageview', 'click', 'outbound', 'contact', 'scroll', 'engage', 'view', 'form_start', 'form_submit', 'rage', 'error', 'vital'];

    /** Les gestes qui montrent qu'une visite n'est pas un simple rebond. */
    private const INTERACTIONS = ['click', 'outbound', 'contact', 'form_start', 'form_submit', 'rage'];

    public function __construct(private readonly PlatformSettings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('analytics.enabled', true);
    }

    /** Durée de conservation des données brutes, en jours. */
    public function retentionDays(): int
    {
        return max(30, (int) ($this->settings->get('analytics.retention_days') ?: config('analytics.retention_days', 400)));
    }

    /** Le fuseau dans lequel on range le jour et l'heure d'un événement (celui de la plateforme). */
    public static function timezone(): string
    {
        return config('analytics.timezone') ?: config('app.timezone', 'UTC');
    }

    /* ------------------------------------------------------------------------------------------------
       Lots du navigateur
       ------------------------------------------------------------------------------------------------ */

    /** @param array<string,mixed> $payload */
    public function collect(Request $request, array $payload): void
    {
        if (! $this->enabled() || $this->ignored($request)) {
            return;
        }

        $visitor = $this->key($payload['v'] ?? null);
        $sessionKey = $this->key($payload['s'] ?? null);
        if (! $visitor || ! $sessionKey) {
            return;
        }

        $events = array_values(array_filter(
            array_slice((array) ($payload['e'] ?? []), 0, 50),
            fn ($e) => is_array($e) && in_array((string) ($e['t'] ?? ''), self::BROWSER_TYPES, true)
        ));
        if ($events === []) {
            return;
        }

        $user = $request->user();
        $audience = ! $user ? AnalyticsSession::VISITOR : ($user->isStaff() ? AnalyticsSession::STAFF : AnalyticsSession::CLIENT);

        $session = $this->session($request, $sessionKey, $visitor, $user, $audience, (array) ($payload['m'] ?? []), $events);

        $pageviews = 0;
        $seconds = 0;
        $interacted = false;
        $lastPage = null;
        $count = 0;

        foreach ($events as $raw) {
            $type = (string) ($raw['t'] ?? '');
            if (! in_array($type, self::BROWSER_TYPES, true)) {
                continue;
            }

            $event = $this->event($type, $raw, $session, $visitor, $user, $audience);
            $count++;

            $pageviews += $type === 'pageview' ? 1 : 0;
            $lastPage = $type === 'pageview' ? $event->path : $lastPage;
            $seconds += $type === 'engage' ? (int) $event->value : 0;
            $interacted = $interacted || in_array($type, self::INTERACTIONS, true) || ($type === 'scroll' && (int) $event->value >= 50);
        }

        if ($count === 0) {
            return;
        }

        $session->forceFill([
            'pageviews' => $session->pageviews + $pageviews,
            'events' => $session->events + $count,
            'duration_seconds' => $session->duration_seconds + $seconds,
            'exit_path' => $lastPage ?? $session->exit_path,
            'last_seen_at' => now(),
            'is_bounce' => $session->is_bounce && ! $interacted && ($session->pageviews + $pageviews) <= 1,
        ])->save();
    }

    /* ------------------------------------------------------------------------------------------------
       Actions de l'application (côté serveur)
       ------------------------------------------------------------------------------------------------ */

    /**
     * Une action dans l'application (« bot.created », « payment.recorded »...), rattachée à la visite en cours quand le
     * navigateur l'a signalée. Ne lève jamais d'exception : mesurer ne doit rien casser.
     *
     * @param  array<string,scalar|null>  $props
     */
    public function action(string $name, array $props = [], ?User $user = null, ?int $workspaceId = null, ?Request $request = null): void
    {
        try {
            if (! $this->enabled()) {
                return;
            }

            $request ??= request();
            $user ??= auth()->user();

            // Un visiteur qui a refusé la mesure l'est aussi pour les actions qui le suivent.
            if ($request && ($request->cookie('_ko') === '1' || $request->header('DNT') === '1' || $request->header('Sec-GPC') === '1')) {
                return;
            }

            $audience = ! $user ? AnalyticsSession::VISITOR : ($user->isStaff() ? AnalyticsSession::STAFF : AnalyticsSession::CLIENT);
            $workspaceId ??= $user?->currentWorkspaceId();

            $visitor = $this->key($request?->cookies->get('_kv')) ?: ($user ? 'u'.$user->id : Str::random(16));
            $session = ($key = $this->key($request?->cookies->get('_ks'))) ? AnalyticsSession::where('session_key', $key)->first() : null;

            $now = CarbonImmutable::now(self::timezone());

            AnalyticsEvent::create([
                'session_id' => $session?->id,
                'visitor_key' => $visitor,
                'user_id' => $user?->id,
                'workspace_id' => $workspaceId,
                'audience' => $audience,
                'type' => 'action',
                'name' => Str::limit($name, 60, ''),
                'path' => $request ? $this->path('/'.ltrim($request->path(), '/')) : null,
                'props' => $this->props($props) ?: null,
                'date' => $now->toDateString(),
                'hour' => $now->hour,
                'dow' => $now->dayOfWeekIso - 1,
                'created_at' => now(),
            ]);

            $session?->increment('events');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ------------------------------------------------------------------------------------------------
       Détails
       ------------------------------------------------------------------------------------------------ */

    /** Le navigateur ne veut pas être mesuré, ou ce n'est pas une personne. */
    public function ignored(Request $request): bool
    {
        if ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1' || $request->cookie('_ko') === '1') {
            return true;
        }

        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match((string) config('analytics.bots'), $agent)) {
            return true;
        }

        // Un lot envoyé depuis une autre origine n'est pas celui de notre page : on l'oublie.
        $origin = $request->headers->get('Origin');

        return $origin !== null && parse_url($origin, PHP_URL_HOST) !== $request->getHost();
    }

    /** @param array<string,mixed> $meta @param list<array<string,mixed>> $events */
    private function session(Request $request, string $key, string $visitor, ?User $user, string $audience, array $meta, array $events): AnalyticsSession
    {
        $session = AnalyticsSession::where('session_key', $key)->first();

        if ($session) {
            // Une visite commencée sans compte, puis connectée : elle devient celle du client (ou de l'équipe).
            if ($user && $session->audience === AnalyticsSession::VISITOR) {
                $session->forceFill(['audience' => $audience, 'user_id' => $user->id, 'workspace_id' => $user->currentWorkspaceId()])->save();
            }

            return $session;
        }

        $agent = UserAgent::describe($request->userAgent());
        $ua = (string) $request->userAgent();
        $device = $agent['mobile'] ? ((str_contains($ua, 'iPad') || (str_contains($ua, 'Android') && ! str_contains($ua, 'Mobile'))) ? 'tablet' : 'mobile') : 'desktop';

        $referrer = $this->referrerHost((string) ($meta['r'] ?? ''), $request->getHost());
        $utm = (array) ($meta['u'] ?? []);
        $utmSource = $this->text($utm['s'] ?? null, 80);
        $utmMedium = $this->text($utm['m'] ?? null, 80);
        $utmCampaign = $this->text($utm['c'] ?? null, 80);

        $timezone = $this->text($meta['tz'] ?? null, 50);
        $language = $this->text(strtolower((string) ($meta['l'] ?? $request->getPreferredLanguage() ?? '')), 10);
        $now = CarbonImmutable::now(self::timezone());

        $entry = collect($events)->firstWhere('t', 'pageview');

        return AnalyticsSession::create([
            'session_key' => $key,
            'visitor_key' => $visitor,
            'user_id' => $user?->id,
            'workspace_id' => $user?->currentWorkspaceId(),
            'audience' => $audience,
            'started_at' => now(),
            'last_seen_at' => now(),
            'date' => $now->toDateString(),
            'hour' => $now->hour,
            'dow' => $now->dayOfWeekIso - 1,
            'is_new' => ! AnalyticsSession::where('visitor_key', $visitor)->exists(),
            'is_pwa' => (bool) ($meta['pwa'] ?? false),
            'entry_path' => $entry ? $this->path((string) ($entry['p'] ?? '/')) : null,
            'exit_path' => $entry ? $this->path((string) ($entry['p'] ?? '/')) : null,
            'source' => $this->source($referrer, $utmMedium, $utmSource, $utmCampaign),
            'referrer_host' => $referrer,
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
            'device' => $device,
            'browser' => $agent['browser'],
            'os' => $agent['os'],
            'language' => $language,
            'timezone' => $timezone,
            'country' => $this->country($timezone, $language),
        ]);
    }

    /** @param array<string,mixed> $raw */
    private function event(string $type, array $raw, AnalyticsSession $session, string $visitor, ?User $user, string $audience): AnalyticsEvent
    {
        $value = isset($raw['x']) && is_numeric($raw['x']) ? (int) round((float) $raw['x']) : null;
        $now = CarbonImmutable::now(self::timezone());

        $value = match ($type) {
            'engage' => $value === null ? null : max(0, min(1800, $value)),
            'scroll' => $value === null ? null : max(0, min(100, $value)),
            'vital' => $value === null ? null : max(0, min(600000, $value)),
            default => $value,
        };

        return AnalyticsEvent::create([
            'session_id' => $session->id,
            'visitor_key' => $visitor,
            'user_id' => $user?->id,
            'workspace_id' => $user?->currentWorkspaceId(),
            'audience' => $audience,
            'type' => $type,
            'name' => $this->text($raw['n'] ?? null, 140),
            'path' => $this->path((string) ($raw['p'] ?? '/')),
            'target' => $this->target((string) ($raw['g'] ?? '')),
            'value' => $value,
            'cta' => (bool) ($raw['c'] ?? false),
            'props' => $this->props((array) ($raw['o'] ?? [])) ?: null,
            'date' => $now->toDateString(),
            'hour' => $now->hour,
            'dow' => $now->dayOfWeekIso - 1,
            'created_at' => now(),
        ]);
    }

    /** Un identifiant aléatoire du navigateur : lettres et chiffres, 16 à 32 caractères. */
    private function key(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9]{16,32}$/', $value) ? $value : null;
    }

    /** Un texte court, sans adresse e-mail ni numéro de téléphone. */
    private function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return Str::limit($this->scrub(trim(preg_replace('/\s+/u', ' ', (string) $value))), $max, '');
    }

    /** Efface ce qui ressemble à une donnée personnelle : adresses e-mail, longues suites de chiffres. */
    public function scrub(string $text): string
    {
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '[e-mail]', $text);

        return preg_replace('/(?:\+?\d[\s.\-]?){7,}/u', '[numéro]', $text);
    }

    /**
     * Le chemin d'une page, sans paramètres, avec les identifiants remplacés : /bots/12/sources devient /bots/:id/sources,
     * pour que toutes les pages du même genre se comptent ensemble.
     */
    public function path(string $path): string
    {
        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $path = preg_replace('#/\d+(?=/|$)#', '/:id', $path);
        $path = preg_replace('#/pk_[A-Za-z0-9]+(?=/|$)#', '/:key', $path);
        $path = preg_replace('#/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?=/|$)#i', '/:token', $path);
        $path = preg_replace('#/[A-Za-z0-9_-]{24,}(?=/|$)#', '/:token', $path);

        return Str::limit($path, 200, '');
    }

    /** La destination d'un clic : le chemin pour un lien interne, domaine et chemin pour un lien externe, jamais les paramètres. */
    private function target(string $target): ?string
    {
        $target = trim($target);
        if ($target === '' || preg_match('#^(mailto|tel|sms|javascript):#i', $target)) {
            return null;
        }

        $parts = parse_url($target);
        if (! $parts) {
            return null;
        }

        $path = $this->path((string) ($parts['path'] ?? '/'));

        return Str::limit(isset($parts['host']) ? strtolower($parts['host']).($path === '/' ? '' : $path) : $path, 250, '');
    }

    /** Le domaine d'où vient le visiteur, s'il vient d'un autre site. */
    private function referrerHost(string $referrer, string $ownHost): ?string
    {
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        return $host !== '' && $host !== preg_replace('/^www\./', '', strtolower($ownHost)) ? Str::limit($host, 120, '') : null;
    }

    /** direct, search, social, ai, email, campaign ou referral. */
    public function source(?string $host, ?string $medium, ?string $utmSource, ?string $campaign): string
    {
        $medium = strtolower((string) $medium);

        if (in_array($medium, ['email', 'newsletter', 'mail'], true)) {
            return 'email';
        }
        if ($campaign || in_array($medium, ['cpc', 'ppc', 'paid', 'paidsocial', 'display', 'banner', 'affiliate'], true)) {
            return 'campaign';
        }

        $host = (string) $host;
        if ($host === '') {
            return $utmSource ? 'campaign' : 'direct';
        }

        foreach (['ai' => 'ai_hosts', 'search' => 'search_hosts', 'social' => 'social_hosts'] as $source => $list) {
            foreach (config("analytics.{$list}") as $needle) {
                if (str_contains($host, $needle)) {
                    return $source;
                }
            }
        }

        return 'referral';
    }

    /** Le pays estimé : d'après le fuseau horaire du navigateur, sinon la région de sa langue (fr-BF). */
    private function country(?string $timezone, ?string $language): ?string
    {
        if ($timezone && ($code = config('analytics.timezones.'.$timezone))) {
            return $code;
        }

        if ($language && preg_match('/^[a-z]{2,3}[-_]([a-z]{2})$/i', $language, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /** @param array<mixed> $props @return array<string,scalar> */
    private function props(array $props): array
    {
        $clean = [];

        foreach ($props as $key => $value) {
            if (count($clean) >= 6) {
                break;
            }
            if (! is_scalar($value) || ! is_string($key)) {
                continue;
            }

            $clean[Str::limit(preg_replace('/[^A-Za-z0-9_.-]/', '', $key), 24, '')] = is_string($value) ? Str::limit($this->scrub($value), 80, '') : $value;
        }

        return array_filter($clean, fn ($v, $k) => $k !== '' && $v !== '', ARRAY_FILTER_USE_BOTH);
    }
}
