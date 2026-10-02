<?php

namespace App\Services\Chats;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Support\Text;
use Illuminate\Support\Collection;

/**
 * Lit une conversation comme le ferait un responsable de la qualité : comment elle s'est terminée, ce qui a mal tourné, ce
 * qu'il faudrait faire. Tout est déterministe (aucun appel à l'IA, aucun coût) et calculé à partir de ce qui est déjà enregistré
 * dans la conversation : messages, motifs de non-réponse, avis, délais.
 */
final class ChatDiagnosis
{
    /** Les motifs enregistrés par l'assistant, en clair pour l'équipe. */
    public const REASONS = [
        'human_requested' => 'Le client a demandé une personne',
        'bot_inactive' => 'Assistant en pause : le client n\'a pas été servi',
        'workspace_suspended' => 'Espace suspendu : le client n\'a pas été servi',
        'trial_expired' => 'Essai terminé : le client n\'a pas été servi',
        'quota_exceeded' => 'Volume du mois atteint : le client n\'a pas été servi',
        'no_context' => 'Aucun extrait pertinent dans les connaissances',
        'llm_error' => 'Panne de l\'IA : le client a reçu un message d\'excuse',
        'refusal' => 'L\'IA a refusé de répondre',
    ];

    /**
     * @param  Collection<int,Message>  $messages  les messages de la conversation, du plus ancien au plus récent
     * @return array{
     *     outcome:array{key:string,label:string,tone:string,hint:string},
     *     score:?int, grade:?array{label:string,tone:string},
     *     signals:list<array{level:string,text:string}>,
     *     facts:array<string,mixed>,
     *     repeated:list<string>,
     *     timeline:list<array{message:Message,gap:?string,day:?string,slow:bool}>
     * }
     */
    public static function for(Conversation $c, Collection $messages): array
    {
        $users = $messages->where('role', Message::USER);
        $assistant = $messages->where('role', Message::ASSISTANT);
        $agents = $messages->where('role', Message::AGENT);
        $ungrounded = $assistant->filter(fn (Message $m) => $m->isUngrounded());
        $down = $messages->filter(fn (Message $m) => ($m->meta['feedback'] ?? null) === 'down');
        $up = $messages->filter(fn (Message $m) => ($m->meta['feedback'] ?? null) === 'up');
        $reasons = $assistant->map(fn (Message $m) => $m->meta['reason'] ?? null)->filter()->countBy()->all();
        $unserved = array_sum(array_intersect_key($reasons, array_flip(ChatQuery::UNSERVED)));
        $errors = array_sum(array_intersect_key($reasons, array_flip(ChatQuery::ERRORS)));

        $first = $messages->first();
        $last = $messages->last();
        $duration = $first && $last ? max(0, (int) $first->created_at->diffInSeconds($last->created_at)) : 0;
        $latencies = $assistant->map(fn (Message $m) => $m->meta['latency_ms'] ?? null)->filter(fn ($v) => is_numeric($v) && $v > 0);

        $waiting = null;
        if ($c->isHandledByHuman() && $c->last_inbound_at && ! $agents->contains(fn (Message $m) => $m->created_at->gte($c->last_inbound_at))) {
            $waiting = (int) $c->last_inbound_at->diffInMinutes(now());
        }

        $repeated = self::repeated($users);
        $sensitive = $users->contains(fn (Message $m) => Text::isSensitive($m->content));
        $askedHuman = $users->contains(fn (Message $m) => Text::wantsHuman($m->content));
        $endedOnGap = $last && $last->role === Message::ASSISTANT && $last->isUngrounded();
        $hasLead = Lead::withoutGlobalScopes()->where('conversation_id', $c->id)->exists();

        $signals = [];
        $add = function (string $level, string $text) use (&$signals) {
            $signals[] = ['level' => $level, 'text' => $text];
        };

        if ($waiting !== null && $waiting >= ChatQuery::WAIT_MINUTES) {
            $add('bad', 'Le client attend une réponse humaine depuis '.self::minutes($waiting).'.');
        } elseif ($c->status === Conversation::NEEDS_HUMAN) {
            $add('warn', 'Le client a demandé à parler à une personne.');
        }
        if ($unserved > 0) {
            $add('bad', 'L\'assistant n\'a pas répondu au client ('.self::firstReason($reasons, ChatQuery::UNSERVED).').');
        }
        if ($errors > 0) {
            $add('bad', $errors.' réponse'.($errors > 1 ? 's' : '').' en échec côté IA (panne ou refus) : vérifier « IA et fournisseurs ».');
        }
        if ($ungrounded->count() >= 2) {
            $add('warn', $ungrounded->count().' questions sans réponse dans les connaissances : le contenu de l\'assistant est à compléter.');
        } elseif ($ungrounded->count() === 1) {
            $add('info', 'Une question sans réponse dans les connaissances.');
        }
        if ($down->isNotEmpty()) {
            $add('bad', $down->count().' avis négatif'.($down->count() > 1 ? 's' : '').' du client sur une réponse.');
        }
        if ($sensitive) {
            $add('warn', 'Le client emploie des mots de plainte ou d\'urgence : à lire en entier.');
        }
        if ($askedHuman && $agents->isEmpty() && $waiting === null) {
            $add('warn', 'Le client a demandé une personne, mais personne n\'a pris la main.');
        }
        if ($repeated !== []) {
            $add('warn', 'Le client a répété la même question : il n\'a probablement pas obtenu ce qu\'il cherchait.');
        }
        if ($endedOnGap) {
            $add('warn', 'La conversation s\'est arrêtée sur une réponse sans information : le client est reparti sans réponse.');
        }
        if ($latencies->isNotEmpty() && $latencies->avg() > 12000) {
            $add('info', 'Réponses lentes : '.round($latencies->avg() / 1000, 1).' s en moyenne.');
        }
        if ($hasLead) {
            $add('ok', 'Une demande (commande, rendez-vous, devis ou personne) a été enregistrée.');
        }
        if ($up->isNotEmpty()) {
            $add('ok', $up->count().' avis positif'.($up->count() > 1 ? 's' : '').' du client.');
        }
        if ($signals === [] && $assistant->isNotEmpty()) {
            $add('ok', 'Aucun problème repéré : l\'assistant a répondu à partir de ses connaissances.');
        }

        $score = $assistant->isEmpty() || $users->isEmpty()
            ? null
            : self::score($ungrounded->count(), $unserved, $errors, $down->count(), $up->count(), $waiting, $repeated !== [], $sensitive && $agents->isEmpty());

        return [
            'outcome' => self::outcome($c, $users, $assistant, $agents, $ungrounded, $unserved, $errors, $waiting, $endedOnGap),
            'score' => $score,
            'grade' => self::grade($score),
            'signals' => $signals,
            'facts' => [
                'messages' => $messages->count(),
                'client' => $users->count(),
                'assistant' => $assistant->count(),
                'agent' => $agents->count(),
                'grounded' => $assistant->count() - $ungrounded->count(),
                'duration' => $duration,
                'latency' => $latencies->isNotEmpty() ? (int) round($latencies->avg()) : null,
                'first_response' => self::firstResponse($messages),
                'waiting' => $waiting,
                'models' => $assistant->map(fn (Message $m) => $m->meta['model'] ?? null)->filter()->unique()->values()->all(),
                'voice' => $users->contains(fn (Message $m) => ! empty($m->meta['voice'])),
                'languages' => $users->map(fn (Message $m) => $m->meta['lang'] ?? null)->filter()->unique()->values()->all(),
            ],
            'repeated' => $repeated,
            'timeline' => self::timeline($messages),
        ];
    }

    /** La mention qui accompagne la note (« Bonne », « À surveiller »...). @return array{label:string,tone:string}|null */
    public static function grade(?int $score): ?array
    {
        return match (true) {
            $score === null => null,
            $score >= 85 => ['label' => 'Bonne', 'tone' => 'green'],
            $score >= 65 => ['label' => 'Correcte', 'tone' => 'blue'],
            $score >= 40 => ['label' => 'À surveiller', 'tone' => 'amber'],
            default => ['label' => 'Problématique', 'tone' => 'red'],
        };
    }

    private static function score(int $ungrounded, int $unserved, int $errors, int $down, int $up, ?int $waiting, bool $repeated, bool $sensitiveAlone): int
    {
        $score = 100
            - min(45, 15 * $ungrounded)
            - ($unserved > 0 ? 35 : 0)
            - ($errors > 0 ? 20 : 0)
            - min(50, 25 * $down)
            - ($waiting !== null && $waiting >= ChatQuery::WAIT_MINUTES ? 20 : 0)
            - ($repeated ? 10 : 0)
            - ($sensitiveAlone ? 10 : 0)
            + min(10, 5 * $up);

        return max(0, min(100, $score));
    }

    /** @return array{key:string,label:string,tone:string,hint:string} */
    private static function outcome(Conversation $c, Collection $users, Collection $assistant, Collection $agents, Collection $ungrounded, int $unserved, int $errors, ?int $waiting, bool $endedOnGap): array
    {
        $o = fn (string $key, string $label, string $tone, string $hint) => compact('key', 'label', 'tone', 'hint');

        return match (true) {
            $users->isEmpty() => $o('vide', 'Sans question', 'gray', 'Le client a ouvert l\'assistant sans rien écrire.'),
            $unserved > 0 && $assistant->count() === $unserved => $o('non_servi', 'Client non servi', 'red', 'L\'assistant n\'a pu répondre à aucune question : pause, essai terminé, compte suspendu ou volume atteint.'),
            $waiting !== null && $waiting >= ChatQuery::WAIT_MINUTES => $o('attente', 'Attend une personne', 'red', 'Le client a demandé de l\'aide et attend depuis '.self::minutes($waiting).'.'),
            $c->status === Conversation::NEEDS_HUMAN => $o('attente', 'Attend une personne', 'amber', 'Le transfert vers une personne est demandé.'),
            $c->status === Conversation::HUMAN => $o('humain', 'Prise en charge par une personne', 'blue', $agents->isNotEmpty() ? 'Un conseiller a répondu.' : 'Un conseiller a la main, sans réponse écrite pour l\'instant.'),
            $errors > 0 && $errors >= $assistant->count() => $o('panne', 'Panne de l\'IA', 'red', 'Aucune réponse correcte n\'a pu être donnée : vérifier les fournisseurs d\'IA.'),
            $endedOnGap || ($assistant->isNotEmpty() && $ungrounded->count() === $assistant->count()) => $o('sans_reponse', 'Restée sans réponse', 'amber', 'L\'assistant n\'avait pas l\'information : compléter ses connaissances.'),
            $c->status === Conversation::CLOSED => $o('terminee', 'Terminée', 'gray', 'La conversation a été clôturée.'),
            $ungrounded->isNotEmpty() => $o('partielle', 'Réponse partielle', 'amber', 'L\'assistant a répondu à une partie des questions seulement.'),
            default => $o('resolue', 'Résolue par l\'assistant', 'green', 'Les questions ont trouvé leur réponse dans les connaissances.'),
        };
    }

    /** Les questions que le client a posées plusieurs fois (mêmes mots, sans tenir compte des accents ni de la casse). @return list<string> */
    private static function repeated(Collection $users): array
    {
        return $users->filter(fn (Message $m) => mb_strlen(trim($m->content)) >= 8)
            ->groupBy(fn (Message $m) => mb_substr(ChatQuery::key($m->content), 0, 60))
            ->filter(fn (Collection $g) => $g->count() >= 2)
            ->map(fn (Collection $g) => Text::limit(trim($g->first()->content), 90))
            ->values()->take(3)->all();
    }

    /** Le temps d'attente du client avant la première réponse (secondes), ou null. */
    private static function firstResponse(Collection $messages): ?int
    {
        $question = $messages->firstWhere('role', Message::USER);
        $answer = $question ? $messages->first(fn (Message $m) => $m->id > $question->id && $m->role !== Message::USER) : null;

        return $question && $answer ? max(0, (int) $question->created_at->diffInSeconds($answer->created_at)) : null;
    }

    /**
     * La conversation avec, entre chaque message, le temps écoulé et un repère de changement de jour.
     * Un client qui attend plus de 10 minutes entre sa question et la réponse est signalé (« slow »).
     *
     * @return list<array{message:Message,gap:?string,day:?string,slow:bool}>
     */
    private static function timeline(Collection $messages): array
    {
        $out = [];
        $previous = null;

        foreach ($messages as $message) {
            $seconds = $previous ? max(0, (int) $previous->created_at->diffInSeconds($message->created_at)) : null;
            $newDay = ! $previous || ! $previous->created_at->isSameDay($message->created_at);

            $out[] = [
                'message' => $message,
                'gap' => $seconds !== null && $seconds >= 120 ? self::duration($seconds) : null,
                'day' => $newDay ? $message->created_at->locale('fr')->isoFormat('dddd D MMMM YYYY') : null,
                'slow' => $previous && $previous->role === Message::USER && $message->role !== Message::USER && $seconds >= 600,
            ];
            $previous = $message;
        }

        return $out;
    }

    /** @param array<string,int> $reasons @param list<string> $among */
    private static function firstReason(array $reasons, array $among): string
    {
        foreach ($among as $key) {
            if (isset($reasons[$key])) {
                return mb_strtolower(self::REASONS[$key] ?? $key);
            }
        }

        return 'motif inconnu';
    }

    public static function minutes(int $minutes): string
    {
        return $minutes < 60 ? $minutes.' min' : ($minutes < 2880 ? intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '') : intdiv($minutes, 1440).' jours');
    }

    private static function duration(int $seconds): string
    {
        return $seconds < 3600 ? intdiv($seconds, 60).' min' : (intdiv($seconds, 3600).' h'.(($seconds % 3600) >= 60 ? ' '.intdiv($seconds % 3600, 60).' min' : ''));
    }
}
