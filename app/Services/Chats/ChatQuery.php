<?php

namespace App\Services\Chats;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\CustomerRhythm;
use App\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Toutes les conversations de la plateforme, vues par le super administrateur : les visiteurs de l'assistant de Kouma comme les
 * clients de chaque entreprise cliente, tous canaux confondus. Les lectures passent par `withoutGlobalScopes()` ou par le
 * constructeur de requêtes : le périmètre par entreprise ne doit pas réduire la vue de la plateforme (même quand le super
 * administrateur est « entré » dans l'espace d'un client). Les signaux sont calculés en SQL portable (MySQL, SQLite).
 */
final class ChatQuery
{
    /** Un client qui attend une personne depuis plus que cela est « à surveiller ». */
    public const WAIT_MINUTES = 30;

    /** L'assistant n'a pas servi le client : en pause, essai terminé, compte suspendu, volume atteint. */
    public const UNSERVED = ['bot_inactive', 'workspace_suspended', 'trial_expired', 'quota_exceeded'];

    public const ERRORS = ['llm_error', 'refusal'];

    /** Des mots de plainte ou d'urgence (sans accents : la recherche ne distingue pas la casse). */
    public const COMPLAINTS = ['arnaque', 'escroc', 'rembours', 'plainte', 'inacceptable', 'scandale', 'honte', 'catastroph', 'jamais recu', 'pas recu', 'mecontent', 'complaint', 'refund', 'scam', 'unacceptable'];

    /** Les mots d'un visiteur qui s'intéresse à l'offre de la plateforme. */
    public const INTENT = ['prix', 'tarif', 'combien', 'coute', 'essai', 'gratuit', 'inscri', 'devis', 'abonn', 'demo', 'offre', 'payer', 'paiement', 'commencer', 'creer mon'];

    public function __construct(public readonly ChatFilters $f) {}

    /* ------------------------------------------------------------------------------------------------
       Périmètre
       ------------------------------------------------------------------------------------------------ */

    /** L'assistant de la page d'accueil de la plateforme (celui qui répond aux visiteurs), s'il est choisi dans les paramètres. */
    public static function landingBot(): ?Bot
    {
        return Bot::landing();
    }

    /** @return Builder<Conversation> */
    private function conversations(): Builder
    {
        return Conversation::withoutGlobalScopes();
    }

    /**
     * Les conversations de la vue et de la période, avant les filtres fins et la recherche : c'est sur elles que se calculent
     * les chiffres de l'en-tête.
     *
     * @return Builder<Conversation>
     */
    public function scope(bool $applyPeriod = true): Builder
    {
        $q = $this->conversations();
        $landing = self::landingBot();

        match ($this->f->view) {
            'kouma', 'prospects' => $landing ? $q->where('conversations.bot_id', $landing->id) : $q->whereRaw('1 = 0'),
            'clients' => $landing ? $q->where('conversations.bot_id', '!=', $landing->id) : $q,
            default => null,
        };

        if (! $this->f->withTests && $this->f->channel !== 'playground') {
            $q->where('conversations.channel', '!=', 'playground');
        }

        if ($applyPeriod) {
            $q->where('conversations.last_message_at', '>=', $this->f->from());
        }

        return $q;
    }

    /** Le périmètre complété des filtres fins (client, assistant, canal, statut) et de la recherche. @return Builder<Conversation> */
    public function filtered(): Builder
    {
        $q = $this->scope();

        $q->when($this->f->workspace, fn ($b, $id) => $b->where('conversations.workspace_id', $id))
            ->when($this->f->bot, fn ($b, $id) => $b->where('conversations.bot_id', $id))
            ->when($this->f->channel, fn ($b, $c) => $b->where('conversations.channel', $c))
            ->when($this->f->status, fn ($b, $s) => $b->where('conversations.status', $s));

        if ($this->f->view === 'surveiller' && ! $this->f->flag) {
            $q->where(function (Builder $any) {
                foreach (['attente', 'sans_reponse', 'mecontent', 'non_servi', 'erreur', 'signale'] as $flag) {
                    $any->orWhere(fn (Builder $one) => $this->flag($one, $flag));
                }
            });
        }

        if ($this->f->view === 'prospects' && ! $this->f->flag) {
            $this->flag($q, 'prospect');
        }

        if ($this->f->flag) {
            $this->flag($q, $this->f->flag);
        }

        if ($this->f->q !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->f->q).'%';
            $q->where(function (Builder $w) use ($term) {
                $w->where('conversations.contact_name', 'like', $term)
                    ->orWhere('conversations.contact_phone', 'like', $term)
                    ->orWhere('conversations.contact_email', 'like', $term)
                    ->orWhereIn('conversations.id', DB::table('messages')->select('conversation_id')->where('content', 'like', $term));
            });
        }

        return $q;
    }

    /* ------------------------------------------------------------------------------------------------
       Signaux
       ------------------------------------------------------------------------------------------------ */

    /** Restreint une requête de conversations à celles qui portent ce signal. @param Builder<Conversation> $q @return Builder<Conversation> */
    public function flag(Builder $q, string $flag): Builder
    {
        $msg = fn (): QueryBuilder => DB::table('messages');

        return match ($flag) {
            // Pris en charge ou en attente d'une personne, sans réponse humaine depuis le dernier message du client.
            'attente' => $q->whereIn('conversations.status', [Conversation::NEEDS_HUMAN, Conversation::HUMAN])
                ->whereNotNull('conversations.last_inbound_at')
                ->where('conversations.last_inbound_at', '<=', Carbon::now()->subMinutes(self::WAIT_MINUTES))
                ->whereNotExists(fn (QueryBuilder $s) => $s->from('messages as a')->selectRaw('1')
                    ->whereColumn('a.conversation_id', 'conversations.id')->where('a.role', Message::AGENT)
                    ->whereColumn('a.created_at', '>=', 'conversations.last_inbound_at')),

            'sans_reponse' => $q->whereIn('conversations.id', $msg()->select('conversation_id')->where('role', Message::ASSISTANT)
                ->where('meta->grounded', false)->groupBy('conversation_id')->havingRaw('count(*) >= 2')),

            'mecontent' => $q->where(fn (Builder $w) => $w
                ->whereIn('conversations.id', $msg()->select('conversation_id')->where('meta->feedback', 'down'))
                ->orWhereIn('conversations.id', $msg()->select('conversation_id')->where('role', Message::USER)
                    ->where(fn (QueryBuilder $k) => collect(self::COMPLAINTS)->each(fn ($word) => $k->orWhere('content', 'like', "%{$word}%"))))),

            'non_servi' => $q->whereIn('conversations.id', $msg()->select('conversation_id')->where('role', Message::ASSISTANT)->whereIn('meta->reason', self::UNSERVED)),

            'erreur' => $q->whereIn('conversations.id', $msg()->select('conversation_id')->where('role', Message::ASSISTANT)->whereIn('meta->reason', self::ERRORS)),

            'prospect' => $q->where(fn (Builder $w) => $w
                ->whereNotNull('conversations.contact_email')
                ->orWhereNotNull('conversations.contact_phone')
                ->orWhereIn('conversations.id', DB::table('leads')->select('conversation_id'))
                ->orWhereIn('conversations.id', $msg()->select('conversation_id')->where('role', Message::USER)
                    ->where(fn (QueryBuilder $k) => collect(self::INTENT)->each(fn ($word) => $k->orWhere('content', 'like', "%{$word}%"))))),

            'signale' => $q->whereIn('conversations.id', DB::table('conversation_notes')->select('conversation_id')->where('kind', 'flag')),

            default => $q,
        };
    }

    /** Combien de conversations portent chaque signal dans la vue et la période (les puces de la page). @return array<string,int> */
    public function flagCounts(): array
    {
        $counts = [];
        foreach (array_keys(ChatFilters::FLAGS) as $flag) {
            if ($flag === 'prospect' && ! in_array($this->f->view, ['kouma', 'prospects'], true)) {
                continue;
            }
            $counts[$flag] = $this->flag($this->scope(), $flag)->count();
        }

        return $counts;
    }

    /* ------------------------------------------------------------------------------------------------
       Liste
       ------------------------------------------------------------------------------------------------ */

    /**
     * La liste paginée : chaque ligne porte ses compteurs (messages, sans réponse, avis négatifs...) calculés en sous-requêtes,
     * pour que les puces de la page ne coûtent pas une requête par conversation.
     */
    public function list(int $perPage = 25)
    {
        $m = fn (): QueryBuilder => DB::table('messages as m')->whereColumn('m.conversation_id', 'conversations.id');

        $q = $this->filtered()
            ->with(['bot' => fn ($b) => $b->withoutGlobalScopes(), 'workspace'])
            ->addSelect(['conversations.*',
                'messages_count' => $m()->selectRaw('count(*)'),
                'user_count' => $m()->selectRaw('count(*)')->where('m.role', Message::USER),
                'ungrounded_count' => $m()->selectRaw('count(*)')->where('m.role', Message::ASSISTANT)->where('m.meta->grounded', false),
                'down_count' => $m()->selectRaw('count(*)')->where('m.meta->feedback', 'down'),
                'unserved_count' => $m()->selectRaw('count(*)')->where('m.role', Message::ASSISTANT)->whereIn('m.meta->reason', self::UNSERVED),
                'agent_count' => $m()->selectRaw('count(*)')->where('m.role', Message::AGENT),
                'leads_count' => DB::table('leads')->selectRaw('count(*)')->whereColumn('leads.conversation_id', 'conversations.id'),
                'flagged_count' => DB::table('conversation_notes')->selectRaw('count(*)')->whereColumn('conversation_notes.conversation_id', 'conversations.id')->where('conversation_notes.kind', 'flag'),
                'reviewed_count' => DB::table('conversation_notes')->selectRaw('count(*)')->whereColumn('conversation_notes.conversation_id', 'conversations.id')->where('conversation_notes.kind', 'review'),
                'first_text' => $m()->select('m.content')->where('m.role', Message::USER)->orderBy('m.id')->limit(1),
                'last_text' => $m()->select('m.content')->orderByDesc('m.id')->limit(1),
            ]);

        match ($this->f->sort) {
            'longues' => $q->orderByDesc('messages_count')->orderByDesc('conversations.last_message_at'),
            'anciennes' => $q->orderBy('conversations.last_message_at'),
            default => $q->orderByDesc('conversations.last_message_at'),
        };

        return $q->paginate($perPage)->withQueryString();
    }

    /**
     * Les signaux d'une ligne de la liste, en clair : ce qui doit attirer l'œil, sans ouvrir la conversation.
     *
     * @return list<array{key:string,label:string,tone:string}>
     */
    public static function badges(Conversation $c, ?int $landingBotId = null): array
    {
        $out = [];
        $waiting = $c->last_inbound_at && in_array($c->status, [Conversation::NEEDS_HUMAN, Conversation::HUMAN], true)
            && $c->last_inbound_at->lte(now()->subMinutes(self::WAIT_MINUTES)) && (int) ($c->agent_count ?? 0) === 0;

        if ($waiting) {
            $out[] = ['key' => 'attente', 'label' => 'Attend depuis '.$c->last_inbound_at->locale('fr')->diffForHumans(null, true), 'tone' => 'red'];
        }

        if ((int) ($c->unserved_count ?? 0) > 0) {
            $out[] = ['key' => 'non_servi', 'label' => 'Client non servi', 'tone' => 'red'];
        }
        if ((int) ($c->ungrounded_count ?? 0) >= 2) {
            $out[] = ['key' => 'sans_reponse', 'label' => $c->ungrounded_count.' sans réponse', 'tone' => 'amber'];
        }
        if ((int) ($c->down_count ?? 0) > 0) {
            $out[] = ['key' => 'mecontent', 'label' => 'Avis négatif', 'tone' => 'red'];
        }
        if ((int) ($c->leads_count ?? 0) > 0) {
            $out[] = ['key' => 'demande', 'label' => 'Demande enregistrée', 'tone' => 'green'];
        }
        if ($landingBotId && $c->bot_id === $landingBotId && ($c->contact_email || $c->contact_phone)) {
            $out[] = ['key' => 'prospect', 'label' => 'Prospect', 'tone' => 'green'];
        }
        if ((int) ($c->flagged_count ?? 0) > 0) {
            $out[] = ['key' => 'signale', 'label' => 'Signalée', 'tone' => 'red'];
        }
        if ((int) ($c->reviewed_count ?? 0) > 0) {
            $out[] = ['key' => 'revue', 'label' => 'Examinée', 'tone' => 'gray'];
        }

        return $out;
    }

    /* ------------------------------------------------------------------------------------------------
       Synthèses
       ------------------------------------------------------------------------------------------------ */

    /**
     * Les chiffres de l'en-tête pour la vue et la période.
     *
     * @return array<string,int|float>
     */
    public function overview(): array
    {
        $ids = fn () => $this->scope()->select('conversations.id');
        $from = $this->f->from();
        $messages = fn (): QueryBuilder => DB::table('messages')->whereIn('conversation_id', $ids())->where('created_at', '>=', $from);

        $conversations = $this->scope()->count();
        $assistant = (clone $messages())->where('role', Message::ASSISTANT)->count();
        $ungrounded = (clone $messages())->where('role', Message::ASSISTANT)->where('meta->grounded', false)->count();

        return [
            'conversations' => $conversations,
            'visitors' => $this->scope()->whereNotNull('conversations.external_id')->distinct()->count('conversations.external_id'),
            'messages' => (clone $messages())->where('role', Message::USER)->count(),
            'answers' => $assistant,
            'grounded_rate' => $assistant > 0 ? round(100 * ($assistant - $ungrounded) / $assistant, 1) : 0.0,
            'ungrounded' => $ungrounded,
            'handoffs' => $this->scope()->whereIn('conversations.status', [Conversation::NEEDS_HUMAN, Conversation::HUMAN])->count(),
            'waiting' => $this->flag($this->scope(false), 'attente')->count(),
            'unserved' => $this->flag($this->scope(), 'non_servi')->count(),
            'negative' => (clone $messages())->where('meta->feedback', 'down')->count(),
            'positive' => (clone $messages())->where('meta->feedback', 'up')->count(),
            'companies' => $this->scope()->distinct()->count('conversations.workspace_id'),
            'leads' => (int) DB::table('leads')->whereIn('conversation_id', $ids())->count(),
        ];
    }

    /** Les conversations ouvertes jour après jour. @return list<array{date:string,label:string,sessions:int,visitors:int,pageviews:int}> */
    public function daily(): array
    {
        $rows = $this->scope()->selectRaw('date(conversations.created_at) as d, count(*) as n')->groupBy('d')->pluck('n', 'd');

        $series = [];
        $from = $this->f->from()->startOfDay();
        for ($day = $from; $day->lte(now()); $day = $day->addDay()) {
            $key = $day->toDateString();
            $n = (int) ($rows[$key] ?? 0);
            // Même forme que les séries de la page Statistiques : le graphique en barres se réutilise tel quel.
            $series[] = ['date' => $key, 'label' => $day->locale('fr')->isoFormat('D MMM'), 'sessions' => $n, 'visitors' => $n, 'pageviews' => $n];
        }

        return $series;
    }

    /** À quelle heure les clients écrivent (carte jour par heure) et par quel canal. @return array<string,mixed> */
    public function rhythm(): array
    {
        $dates = DB::table('messages')->whereIn('conversation_id', $this->scope()->select('conversations.id'))
            ->where('role', Message::USER)->where('created_at', '>=', $this->f->from())->latest('id')->limit(20000)->pluck('created_at');

        return CustomerRhythm::fromDates($dates) + [
            'channels' => CustomerRhythm::channelNames($this->scope()->selectRaw('conversations.channel as c, count(*) as n')->groupBy('c')->orderByDesc('n')->pluck('n', 'c')->all()),
        ];
    }

    /**
     * Une ligne par entreprise cliente : volume, qualité, attente, dernière activité.
     *
     * @return list<array{id:int,name:string,plan:string,conversations:int,messages:int,ungrounded:int,ungrounded_rate:float,handoffs:int,negative:int,last_at:?Carbon}>
     */
    public function companies(int $limit = 100): array
    {
        $rows = $this->scope()
            ->selectRaw("conversations.workspace_id, count(*) as conversations, max(conversations.last_message_at) as last_at, sum(case when conversations.status in ('needs_human','human') then 1 else 0 end) as handoffs")
            ->groupBy('conversations.workspace_id')->orderByDesc('conversations')->limit($limit)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $this->scope()->select('conversations.id');
        $byCompany = fn (QueryBuilder $q) => $q->selectRaw('workspace_id, count(*) as n')->groupBy('workspace_id')->pluck('n', 'workspace_id');
        $base = fn (): QueryBuilder => DB::table('messages')->whereIn('conversation_id', $ids)->where('created_at', '>=', $this->f->from());

        $messages = $byCompany($base()->where('role', Message::USER));
        $assistant = $byCompany($base()->where('role', Message::ASSISTANT));
        $ungrounded = $byCompany($base()->where('role', Message::ASSISTANT)->where('meta->grounded', false));
        $negative = $byCompany($base()->where('meta->feedback', 'down'));
        $workspaces = DB::table('workspaces')->whereIn('id', $rows->pluck('workspace_id'))->get(['id', 'name', 'plan'])->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->workspace_id,
            'name' => (string) ($workspaces[$r->workspace_id]->name ?? 'Espace supprimé'),
            'plan' => (string) ($workspaces[$r->workspace_id]->plan ?? ''),
            'conversations' => (int) $r->conversations,
            'messages' => (int) ($messages[$r->workspace_id] ?? 0),
            'ungrounded' => (int) ($ungrounded[$r->workspace_id] ?? 0),
            'ungrounded_rate' => ($assistant[$r->workspace_id] ?? 0) > 0 ? round(100 * ($ungrounded[$r->workspace_id] ?? 0) / $assistant[$r->workspace_id], 1) : 0.0,
            'handoffs' => (int) $r->handoffs,
            'negative' => (int) ($negative[$r->workspace_id] ?? 0),
            'last_at' => $r->last_at ? Carbon::parse($r->last_at) : null,
        ])->all();
    }

    /**
     * Ce que les gens demandent et que les assistants ne savent pas encore, toutes entreprises confondues : les mêmes questions
     * sont regroupées (sans tenir compte des accents ni de la casse), avec le nombre d'entreprises concernées.
     *
     * @return list<array{question:string,count:int,companies:int,last_at:Carbon,conversation:int,bots:list<string>}>
     */
    public function unanswered(int $limit = 40): array
    {
        $rows = Message::withoutGlobalScopes()
            ->whereIn('messages.conversation_id', $this->scope()->select('conversations.id'))
            ->where('messages.role', Message::ASSISTANT)->where('messages.meta->grounded', false)
            ->where('messages.created_at', '>=', $this->f->from())
            ->addSelect(['messages.id', 'messages.conversation_id', 'messages.workspace_id', 'messages.created_at', 'question' => Message::previousUserContent()])
            ->latest('messages.id')->limit(1500)->get();

        $botNames = DB::table('conversations')->join('bots', 'bots.id', '=', 'conversations.bot_id')
            ->whereIn('conversations.id', $rows->pluck('conversation_id')->unique())->pluck('bots.name', 'conversations.id');

        return $rows->filter(fn ($m) => filled($m->question))
            ->groupBy(fn ($m) => self::key((string) $m->question))
            ->map(fn ($group) => [
                'question' => Str::limit(trim((string) $group->first()->question), 160),
                'count' => $group->count(),
                'companies' => $group->pluck('workspace_id')->unique()->count(),
                'last_at' => Carbon::parse($group->first()->created_at),
                'conversation' => (int) $group->first()->conversation_id,
                'bots' => $group->map(fn ($m) => $botNames[$m->conversation_id] ?? null)->filter()->unique()->take(3)->values()->all(),
            ])
            ->sortByDesc(fn ($row) => [$row['count'], $row['last_at']->timestamp])->take($limit)->values()->all();
    }

    /**
     * Les autres conversations de la même personne chez la même entreprise (même numéro, même e-mail, ou même visiteur du site) :
     * on voit d'un coup d'œil si c'est un client qui revient ou qui n'a jamais obtenu sa réponse.
     *
     * @return \Illuminate\Support\Collection<int,Conversation>
     */
    public static function related(Conversation $c, int $limit = 8)
    {
        $same = fn (Builder $q) => $q->where(function (Builder $w) use ($c) {
            if (filled($c->contact_phone)) {
                $w->orWhere('contact_phone', $c->contact_phone);
            }
            if (filled($c->contact_email)) {
                $w->orWhere('contact_email', $c->contact_email);
            }
            if (filled($c->external_id)) {
                $w->orWhere(fn (Builder $v) => $v->where('channel', $c->channel)->where('external_id', $c->external_id));
            }
        });

        if (! filled($c->contact_phone) && ! filled($c->contact_email) && ! filled($c->external_id)) {
            return collect();
        }

        return $same(Conversation::withoutGlobalScopes()->where('workspace_id', $c->workspace_id)->where('id', '!=', $c->id))
            ->orderByDesc('last_message_at')->limit($limit)->get();
    }

    /** La clé qui regroupe deux formulations de la même question : minuscules, sans accents ni ponctuation. */
    public static function key(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', Text::fold($text)));
    }

    /** Le nom affiché d'un client dans les listes : son nom, sinon son numéro masqué, sinon « Visiteur xxxx ». */
    public static function who(Conversation $c): string
    {
        return $c->contact_name
            ?: (self::maskPhone($c->contact_phone ?: ($c->channel === 'whatsapp' ? $c->external_id : null)) ?: 'Visiteur '.Str::substr((string) $c->token, 0, 4));
    }

    /** « +226 70 12 34 56 » devient « +226 ••• •• 56 » : les listes et les exports ne montrent jamais un numéro en entier. */
    public static function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits === '' ? null : (strlen($digits) <= 4 ? '•••' : '+'.substr($digits, 0, min(3, strlen($digits) - 4)).' ••• •• '.substr($digits, -2));
    }

    /**
     * En direct : les conversations actives dans les 15 dernières minutes et les derniers messages de clients.
     *
     * @return array{active:int, waiting:int, recent:list<array{id:int,text:string,who:string,bot:string,company:string,ago:string}>}
     */
    public static function live(): array
    {
        $f = new ChatFilters(view: 'toutes', days: 1);
        $q = new self($f);
        $since = now()->subMinutes(15);

        $recent = DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('bots as b', 'b.id', '=', 'c.bot_id')
            ->leftJoin('workspaces as w', 'w.id', '=', 'c.workspace_id')
            ->where('m.role', Message::USER)->where('c.channel', '!=', 'playground')->where('m.created_at', '>=', $since)
            ->orderByDesc('m.id')->limit(8)
            ->get(['m.conversation_id', 'm.content', 'm.created_at', 'c.contact_name', 'c.contact_phone', 'c.channel', 'b.name as bot', 'w.name as company']);

        return [
            'active' => $q->conversations()->where('conversations.channel', '!=', 'playground')->where('conversations.last_message_at', '>=', $since)->count(),
            'waiting' => $q->flag($q->conversations()->where('conversations.channel', '!=', 'playground'), 'attente')->count(),
            'recent' => $recent->map(fn ($r) => [
                'id' => (int) $r->conversation_id,
                'text' => Str::limit((string) $r->content, 110),
                'who' => $r->contact_name ?: ($r->contact_phone ? '+'.ltrim(preg_replace('/\D/', '', $r->contact_phone), '+') : 'Visiteur'),
                'bot' => (string) $r->bot,
                'company' => (string) $r->company,
                'ago' => Carbon::parse($r->created_at)->locale('fr')->diffForHumans(),
            ])->all(),
        ];
    }
}
