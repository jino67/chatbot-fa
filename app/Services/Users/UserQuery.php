<?php

namespace App\Services\Users;

use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Toutes les personnes inscrites (comptes clients), avec ce qu'il faut pour les aider : leur entreprise, leur offre, où elles
 * en sont (assistant, connaissances, vraies conversations) et leur dernière activité. Les lectures passent par `DB::table`
 * (les modèles de l'application sont filtrés par entreprise : ici on voit toute la plateforme). SQL portable MySQL et SQLite.
 */
final class UserQuery
{
    private const HAS_BOT = '(exists (select 1 from bots b where b.workspace_id = users.workspace_id))';

    private const HAS_SOURCE = "(exists (select 1 from sources s where s.workspace_id = users.workspace_id and s.status = 'ready'))";

    private const HAS_TEST = "(exists (select 1 from conversations c where c.workspace_id = users.workspace_id and c.channel = 'playground'))";

    private const HAS_LIVE = "(exists (select 1 from conversations c where c.workspace_id = users.workspace_id and c.channel != 'playground') or exists (select 1 from channels ch where ch.workspace_id = users.workspace_id and ch.status = 'active'))";

    private const LAST_SEEN = '(select max(a.last_seen_at) from analytics_sessions a where a.user_id = users.id)';

    public function __construct(public readonly UserFilters $f) {}

    /** Les offres payantes (un prix supérieur à zéro) : le JSON des prix ne se lit pas en SQL portable. @return list<string> */
    public static function paidPlans(): array
    {
        return Plan::query()->get()->reject(fn (Plan $plan) => $plan->isFree())->pluck('slug')->values()->all();
    }

    private function base(): Builder
    {
        return DB::table('users')
            ->leftJoin('workspaces', 'workspaces.id', '=', 'users.workspace_id')
            ->where('users.role', 'client');
    }

    /** Applique un segment (une question de l'équipe) à la requête. */
    private function segment(Builder $q, string $segment): Builder
    {
        $paid = self::paidPlans();
        $notPaid = fn (Builder $b) => $paid === [] ? $b : $b->whereNotIn('workspaces.plan', $paid);
        $now = now();

        return match ($segment) {
            'nouveaux' => $q->where('users.created_at', '>=', $now->copy()->subDays(7)),
            'profil' => $q->where('users.needs_profile', true),
            'sans_assistant' => $q->where('users.needs_profile', false)->whereRaw('not '.self::HAS_BOT),
            'sans_connaissances' => $q->whereRaw(self::HAS_BOT.' and not '.self::HAS_SOURCE),
            'a_tester' => $q->whereRaw(self::HAS_SOURCE.' and not '.self::HAS_TEST.' and not '.self::HAS_LIVE),
            'pas_en_ligne' => $q->whereRaw(self::HAS_TEST.' and not '.self::HAS_LIVE),
            'en_ligne' => $notPaid($q)->whereRaw(self::HAS_LIVE),
            'essai_fin' => $notPaid($q)->whereBetween('workspaces.plan_ends_at', [$now, $now->copy()->addDays(UserStage::TRIAL_ALERT_DAYS)]),
            'essai_expire' => $notPaid($q)->where('workspaces.plan_ends_at', '<', $now),
            'dormants' => $q->where('users.created_at', '<=', $now->copy()->subDays(UserStage::ACTIVE_DAYS))
                ->whereRaw('coalesce('.self::LAST_SEEN.', users.last_login_at, users.created_at) <= ?', [$now->copy()->subDays(UserStage::ACTIVE_DAYS)->toDateTimeString()]),
            'payants' => $paid === [] ? $q->whereRaw('1 = 0') : $q->whereIn('workspaces.plan', $paid),
            'a_relancer' => $q->where('users.crm_next_follow_up_at', '<=', $now)->where(fn (Builder $w) => $w->whereNull('users.crm_status')->orWhereNotIn('users.crm_status', ['stop', 'perdu', 'client'])),
            'interesses' => $q->whereIn('users.crm_status', ['interesse', 'en_discussion']),
            'stop' => $q->where('users.crm_status', 'stop'),
            default => $q,
        };
    }

    /** Les personnes du segment et des filtres choisis. */
    public function filtered(): Builder
    {
        $q = $this->segment($this->base(), $this->f->segment);

        $q->when($this->f->source, fn (Builder $b, string $s) => $s === 'email'
            ? $b->where(fn (Builder $w) => $w->whereNull('users.signup_source')->orWhere('users.signup_source', 'email'))
            : $b->where('users.signup_source', $s))
            ->when($this->f->plan, fn (Builder $b, string $plan) => $b->where('workspaces.plan', $plan))
            ->when($this->f->crm, fn (Builder $b, string $crm) => $crm === 'nouveau'
                ? $b->where(fn (Builder $w) => $w->whereNull('users.crm_status')->orWhere('users.crm_status', 'nouveau'))
                : $b->where('users.crm_status', $crm))
            ->when($this->f->owner, fn (Builder $b, int $id) => $b->where('users.crm_owner_id', $id))
            ->when($this->f->state, fn (Builder $b, string $state) => match ($state) {
                'actif' => $b->where('users.is_active', true),
                'desactive' => $b->where('users.is_active', false),
                default => $b->where('workspaces.is_suspended', true),
            });

        if ($this->f->q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->f->q).'%';
            $q->where(fn (Builder $w) => $w->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like)
                ->orWhere('users.phone', 'like', $like)->orWhere('workspaces.name', 'like', $like)->orWhere('workspaces.phone', 'like', $like));
        }

        return $q;
    }

    /** @return LengthAwarePaginator<int,object> */
    public function list(int $perPage = 25): LengthAwarePaginator
    {
        $paid = self::paidPlans();
        $page = $this->rows()->paginate($perPage)->withQueryString();
        $page->getCollection()->transform(fn ($row) => $this->decorate($row, $paid));

        return $page;
    }

    /** Toute la sélection (5 000 personnes au plus), une par une : pour l'export. @return \Generator<int,object> */
    public function each(): \Generator
    {
        $paid = self::paidPlans();

        foreach ($this->rows()->limit(5000)->cursor() as $row) {
            yield $this->decorate($row, $paid);
        }
    }

    /** La requête des lignes, avec les faits et l'ordre choisi. */
    private function rows(): Builder
    {
        $q = $this->filtered()->selectRaw(
            'users.id, users.name, users.email, users.phone, users.created_at, users.last_login_at, users.is_active, users.needs_profile, users.has_password, users.signup_source, users.email_verified_at, '
            .'users.crm_status, users.crm_next_follow_up_at, users.crm_last_contacted_at, users.crm_owner_id, users.workspace_id, '
            .'workspaces.name as company, workspaces.plan, workspaces.country, workspaces.plan_ends_at, workspaces.is_suspended, '
            .'(select count(*) from bots b where b.workspace_id = users.workspace_id) as bots_count, '
            ."(select count(*) from sources s where s.workspace_id = users.workspace_id and s.status = 'ready') as sources_count, "
            ."(select count(*) from conversations c where c.workspace_id = users.workspace_id and c.channel = 'playground') as tests_count, "
            ."(select count(*) from conversations c where c.workspace_id = users.workspace_id and c.channel != 'playground') as conversations_count, "
            ."(select count(*) from channels ch where ch.workspace_id = users.workspace_id and ch.status = 'active') as channels_count, "
            .self::LAST_SEEN.' as last_seen'
        );

        match ($this->f->sort) {
            'activite' => $q->orderByRaw('coalesce('.self::LAST_SEEN.', users.last_login_at, users.created_at) desc'),
            'nom' => $q->orderBy('users.name'),
            'relance' => $q->orderByRaw('users.crm_next_follow_up_at is null')->orderBy('users.crm_next_follow_up_at'),
            default => $q->orderByDesc('users.created_at'),
        };

        return $q->orderByDesc('users.id');
    }

    /** @param list<string> $paid */
    private function decorate(object $row, array $paid): object
    {
        $row->paid = in_array($row->plan, $paid, true);
        $row->last_activity = collect([$row->last_seen, $row->last_login_at])->filter()->max();
        $row->stage = UserStage::for($row);

        return $row;
    }

    /**
     * Combien de personnes dans chaque segment (les puces de la page). Les segments ne tiennent pas compte des autres filtres :
     * ce sont les repères de toute la base.
     *
     * @return array<string,int>
     */
    public function segmentCounts(): array
    {
        $counts = [];
        foreach (array_keys(UserFilters::SEGMENTS) as $segment) {
            $counts[$segment] = $this->segment($this->base(), $segment)->count();
        }

        return $counts;
    }

    /** Les chiffres de l'en-tête. @return array{total:int, week:int, month_active:int, paying:int} */
    public function overview(): array
    {
        $now = now();
        $paid = self::paidPlans();

        return [
            'total' => $this->base()->count(),
            'week' => $this->base()->where('users.created_at', '>=', $now->copy()->subDays(7))->count(),
            'month_active' => $this->base()->whereRaw('coalesce('.self::LAST_SEEN.', users.last_login_at) >= ?', [$now->copy()->subDays(30)->toDateTimeString()])->count(),
            'paying' => $paid === [] ? 0 : $this->base()->whereIn('workspaces.plan', $paid)->count(),
        ];
    }

    /**
     * Où les personnes s'inscrivent : un décompte par provenance (e-mail, Google...).
     *
     * @return array<string,int>
     */
    public function bySource(): array
    {
        $rows = $this->base()->selectRaw("coalesce(users.signup_source, 'email') as src, count(*) as n")->groupBy('src')->orderByDesc('n')->pluck('n', 'src');

        return $rows->map(fn ($n) => (int) $n)->all();
    }
}
