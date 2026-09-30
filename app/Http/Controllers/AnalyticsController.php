<?php

namespace App\Http\Controllers;

use App\Jobs\IngestSource;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Source;
use App\Services\UsageService;
use App\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AnalyticsController extends Controller
{
    public function show(Bot $bot)
    {
        $since = now()->subDays(30);
        $conversationIds = $bot->conversations()->real()->select('id');

        $assistant = Message::whereIn('conversation_id', $conversationIds)
            ->where('role', Message::ASSISTANT)
            ->where('created_at', '>=', $since);

        $answered = (clone $assistant)->where('meta->grounded', true)->count();
        $unanswered = (clone $assistant)->where('meta->grounded', false)->count();

        // Questions restees sans reponse, regroupees : c'est la liste de travail pour enrichir la base.
        $questions = (clone $assistant)
            ->where('meta->grounded', false)
            ->addSelect(['question' => Message::previousUserContent()])
            ->latest('id')
            ->limit(400)
            ->get()
            ->reject(fn ($m) => ($m->meta['resolved'] ?? false) || ! $m->question)
            ->groupBy(fn ($m) => Text::fold(trim($m->question)))
            ->map(fn ($group) => ['question' => $group->first()->question, 'count' => $group->count(), 'last' => $group->first()->created_at])
            ->sortByDesc('count')
            ->take(20)
            ->values();

        $perDay = Conversation::where('bot_id', $bot->id)->real()
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($c) => $c->created_at->format('Y-m-d'))
            ->map->count();

        $days = collect(range(13, 0))->map(fn ($i) => [
            'label' => now()->subDays($i)->format('d/m'),
            'count' => $perDay[now()->subDays($i)->format('Y-m-d')] ?? 0,
        ]);

        return view('analytics.show', [
            'bot' => $bot,
            'days' => $days,
            'kpis' => [
                'conversations' => $bot->conversations()->real()->where('created_at', '>=', $since)->count(),
                'answer_rate' => ($answered + $unanswered) > 0 ? round(100 * $answered / ($answered + $unanswered)) : null,
                'handoffs' => $bot->conversations()->real()->whereIn('status', [Conversation::NEEDS_HUMAN, Conversation::HUMAN])->count(),
                'latency' => (int) round((clone $assistant)->where('meta->llm', true)->get(['meta'])->avg(fn ($m) => $m->meta['latency_ms'] ?? 0)),
            ],
            'questions' => $questions,
        ]);
    }

    /** Transforme une question sans reponse en fiche Question / Reponse indexee immediatement. */
    public function answer(Request $request, Bot $bot, UsageService $usage): RedirectResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:3000'],
        ]);

        if (! $usage->canAddSource($request->user()->currentWorkspace())) {
            return back()->with('error', 'Votre offre a atteint sa limite de sources.');
        }

        $source = Source::create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'type' => Source::TYPE_QA,
            'name' => Str::limit($data['question'], 100, '…'),
            'payload' => ['question' => $data['question'], 'answer' => $data['answer']],
        ]);
        IngestSource::dispatch($source->id);

        // Marque comme traitees les occurrences de cette question.
        $key = Text::fold(trim($data['question']));
        Message::whereIn('conversation_id', $bot->conversations()->real()->select('id'))
            ->where('role', Message::ASSISTANT)
            ->where('meta->grounded', false)
            ->addSelect(['question' => Message::previousUserContent()])
            ->latest('id')->limit(400)->get()
            ->filter(fn ($m) => Text::fold(trim((string) $m->question)) === $key)
            ->each(fn ($m) => $m->update(['meta' => array_merge($m->meta ?? [], ['resolved' => true])]));

        return back()->with('status', 'Réponse ajoutée : l\'assistant saura répondre à cette question.');
    }
}
