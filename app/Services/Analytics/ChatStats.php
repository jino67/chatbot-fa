<?php

namespace App\Services\Analytics;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ce que les visiteurs demandent à l'assistant de la page d'accueil de la plateforme (celui qui répond sur les prix et
 * les offres). Lecture seule, filtrée explicitement sur ses conversations : hors requête d'un client, le périmètre
 * par entreprise ne s'applique pas tout seul.
 */
class ChatStats
{
    /** @return array{available:bool, conversations:int, questions:int, per_conversation:float, handoffs:int, by_hour:list<int>, recent:list<array{text:string,at:string}>} */
    public static function landing(Stats $stats): array
    {
        $bot = Bot::landing();
        $empty = ['available' => false, 'conversations' => 0, 'questions' => 0, 'per_conversation' => 0.0, 'handoffs' => 0, 'by_hour' => array_fill(0, 24, 0), 'recent' => []];

        if (! $bot) {
            return $empty;
        }

        $tz = Tracker::timezone();
        $from = $stats->from->startOfDay()->setTimezone(config('app.timezone'));
        $to = $stats->to->endOfDay()->setTimezone(config('app.timezone'));

        $conversations = Conversation::withoutGlobalScopes()->where('workspace_id', $bot->workspace_id)->where('bot_id', $bot->id)
            ->where('channel', '!=', 'playground')->whereBetween('created_at', [$from, $to]);

        $ids = (clone $conversations)->pluck('id');
        $count = $ids->count();

        $questions = Message::withoutGlobalScopes()->where('workspace_id', $bot->workspace_id)->whereIn('conversation_id', $ids)->where('role', Message::USER);

        $byHour = array_fill(0, 24, 0);
        (clone $questions)->get(['created_at'])->each(function ($m) use (&$byHour, $tz) {
            $byHour[Carbon::parse($m->created_at)->setTimezone($tz)->hour]++;
        });

        $total = (clone $questions)->count();

        return [
            'available' => true,
            'conversations' => $count,
            'questions' => $total,
            'per_conversation' => $count > 0 ? round($total / $count, 1) : 0.0,
            'handoffs' => (clone $conversations)->whereIn('status', [Conversation::NEEDS_HUMAN, Conversation::HUMAN])->count(),
            'by_hour' => $byHour,
            'recent' => (clone $questions)->latest('id')->limit(15)->get(['content', 'created_at'])
                ->map(fn ($m) => ['text' => Str::limit(app(Tracker::class)->scrub((string) $m->content), 160, '…'), 'at' => Carbon::parse($m->created_at)->locale('fr')->diffForHumans()])->all(),
        ];
    }
}
