<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Chats\ChatDiagnosis;
use App\Services\Chats\ChatFilters;
use App\Services\Chats\ChatQuery;
use App\Services\Chats\ChatSummarizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supervision de toutes les conversations de la plateforme, réservée au super administrateur : les visiteurs de l'assistant de
 * Kouma et les clients de chaque entreprise cliente. Lecture seule sur les conversations elles-mêmes (on ne répond pas à la
 * place d'un client ici : « Entrer dans l'espace » existe pour cela). Chaque consultation d'un échange est inscrite au journal.
 */
class ChatSupervisionController extends Controller
{
    public function index(Request $request)
    {
        $filters = ChatFilters::fromRequest($request);
        $query = new ChatQuery($filters);
        $landing = ChatQuery::landingBot();

        $data = [
            'filters' => $filters,
            'views' => ChatFilters::VIEWS,
            'landing' => $landing,
            'flagCounts' => in_array($filters->view, ChatFilters::LISTING, true) ? $query->flagCounts() : [],
        ];

        if (in_array($filters->view, ChatFilters::LISTING, true)) {
            $data += [
                'live' => ChatQuery::live(),
                'overview' => $query->overview(),
                'daily' => $query->daily(),
                'rhythm' => $request->boolean('rythme') ? $query->rhythm() : null,
                'conversations' => $query->list(),
                'workspaces' => Workspace::orderBy('name')->get(['id', 'name']),
                'bots' => Bot::withoutGlobalScopes()->when($filters->workspace, fn ($q, $id) => $q->where('workspace_id', $id))->orderBy('name')->get(['id', 'name', 'workspace_id']),
                'landingId' => $landing?->id,
            ];
        } elseif ($filters->view === 'questions') {
            $data['questions'] = $query->unanswered();
        } else {
            $data['companies'] = $query->companies();
        }

        return view('admin.chats.index', $data);
    }

    /** Le panneau « en direct » de la page, rafraîchi toutes les 20 secondes. */
    public function live(): JsonResponse
    {
        return response()->json(ChatQuery::live());
    }

    public function show(Request $request, int $id)
    {
        $conversation = Conversation::withoutGlobalScopes()->with(['workspace'])->findOrFail($id);
        $bot = Bot::withoutGlobalScopes()->find($conversation->bot_id);
        $messages = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('id')->get();

        // Une consultation par heure et par conversation suffit au journal : une page qui se recharge ne le remplit pas.
        if (Cache::add('chat-viewed:'.$request->user()->id.':'.$conversation->id, 1, 3600)) {
            AuditLog::record('chat.viewed', $this->subject($conversation, $bot), ['conversation' => $conversation->id], $conversation->workspace_id);
        }

        $notes = ConversationNote::with('author:id,name')->where('conversation_id', $conversation->id)->latest('id')->get();

        return view('admin.chats.show', [
            'conversation' => $conversation,
            'bot' => $bot,
            'isLanding' => $bot && ChatQuery::landingBot()?->id === $bot->id,
            'diagnosis' => ChatDiagnosis::for($conversation, $messages),
            'related' => ChatQuery::related($conversation),
            'leads' => \App\Models\Lead::withoutGlobalScopes()->where('conversation_id', $conversation->id)->latest('id')->get(),
            'notes' => $notes->where('kind', ConversationNote::NOTE)->values(),
            'summary' => $notes->firstWhere('kind', ConversationNote::SUMMARY),
            'flagged' => $notes->contains('kind', ConversationNote::FLAG),
            'reviewed' => $notes->contains('kind', ConversationNote::REVIEW),
            'back' => $this->backUrl($request),
        ]);
    }

    public function note(Request $request, int $id): RedirectResponse
    {
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        ConversationNote::create(['conversation_id' => $conversation->id, 'user_id' => $request->user()->id, 'kind' => ConversationNote::NOTE, 'body' => trim($data['body'])]);
        AuditLog::record('chat.note', $this->subject($conversation), ['conversation' => $conversation->id], $conversation->workspace_id);

        return back()->with('status', 'Note ajoutée. Elle n\'est visible que de l\'équipe de la plateforme.');
    }

    /** Pose ou retire « examinée » (la conversation sort de la file « À surveiller » pour les relecteurs). */
    public function review(Request $request, int $id): RedirectResponse
    {
        return $this->toggle($request, $id, ConversationNote::REVIEW, 'chat.reviewed', 'Conversation marquée comme examinée.', 'Marque « examinée » retirée.');
    }

    public function flag(Request $request, int $id): RedirectResponse
    {
        return $this->toggle($request, $id, ConversationNote::FLAG, 'chat.flagged', 'Conversation signalée : elle apparaît dans « À surveiller ».', 'Signalement retiré.');
    }

    /** Un résumé demandé à la main : appel payant à l'IA, jamais imputé à un client, limité à quelques demandes par minute. */
    public function summarize(Request $request, int $id, ChatSummarizer $summarizer): RedirectResponse
    {
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($id);
        $messages = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('id')->get();

        $result = $summarizer->summarize($messages);

        // Un seul résumé est gardé : le dernier remplace le précédent.
        ConversationNote::where('conversation_id', $conversation->id)->where('kind', ConversationNote::SUMMARY)->delete();
        ConversationNote::create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'kind' => ConversationNote::SUMMARY,
            'body' => $result['text'],
            'meta' => ['source' => $result['source'], 'messages' => $messages->count()],
        ]);
        AuditLog::record('chat.summarized', $this->subject($conversation), ['conversation' => $conversation->id, 'source' => $result['source']], $conversation->workspace_id);

        return back()->with('status', $result['source'] === 'ia' ? 'Résumé fait par l\'IA.' : 'Résumé simplifié : aucune IA n\'est disponible pour le moment.');
    }

    /** L'échange en texte brut, pour le joindre à un dossier. Numéro masqué ; l'export est inscrit au journal. */
    public function transcript(Request $request, int $id): StreamedResponse
    {
        $conversation = Conversation::withoutGlobalScopes()->with('workspace')->findOrFail($id);
        $bot = Bot::withoutGlobalScopes()->find($conversation->bot_id);
        $messages = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('id')->get();

        AuditLog::record('chat.exported', $this->subject($conversation, $bot), ['conversation' => $conversation->id, 'format' => 'txt'], $conversation->workspace_id);

        $labels = [Message::USER => 'Client', Message::ASSISTANT => 'Assistant', Message::AGENT => 'Conseiller', Message::SYSTEM => 'Système'];
        $head = [
            'Conversation n° '.$conversation->id.' ('.config('app.name', 'Kouma').')',
            'Entreprise : '.($conversation->workspace?->name ?? 'inconnue').' | Assistant : '.($bot?->name ?? 'inconnu'),
            'Canal : '.(ChatFilters::CHANNELS[$conversation->channel] ?? $conversation->channel).' | Statut : '.(ChatFilters::STATUSES[$conversation->status] ?? $conversation->status),
            'Contact : '.($conversation->contact_name ?: 'non communiqué').($conversation->contact_phone ? ' | '.ChatQuery::maskPhone($conversation->contact_phone) : ''),
            'Exporté le '.now()->locale('fr')->isoFormat('D MMMM YYYY [à] HH:mm').' par l\'équipe de la plateforme (consultation inscrite au journal).',
            str_repeat('-', 60),
        ];

        $name = 'conversation-'.$conversation->id.'-'.now()->format('Y-m-d').'.txt';

        return response()->streamDownload(function () use ($head, $messages, $labels) {
            echo "\xEF\xBB\xBF".implode("\r\n", $head)."\r\n\r\n";
            foreach ($messages as $m) {
                echo '['.$m->created_at->format('d/m/Y H:i').'] '.($labels[$m->role] ?? $m->role).' : '.str_replace("\n", "\r\n    ", (string) $m->content)."\r\n";
            }
        }, $name, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** La liste filtrée en CSV (séparateur « ; » et BOM pour Excel) : numéros masqués, pas d'e-mail, formules neutralisées. */
    public function export(Request $request): StreamedResponse
    {
        $filters = ChatFilters::fromRequest($request);
        $query = new ChatQuery($filters);

        AuditLog::record('chat.exported', 'Liste des conversations', ['format' => 'csv', 'filters' => $filters->toQuery()]);

        $name = 'kouma-conversations-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['N°', 'Dernier message', 'Entreprise', 'Assistant', 'Canal', 'Statut', 'Contact', 'Messages', 'Sans réponse', 'Avis négatifs', 'Demandes', 'Première question'], ';');

            $query->filtered()->with(['bot' => fn ($b) => $b->withoutGlobalScopes(), 'workspace'])
                ->addSelect(['conversations.*',
                    'messages_count' => \Illuminate\Support\Facades\DB::table('messages as m')->selectRaw('count(*)')->whereColumn('m.conversation_id', 'conversations.id'),
                    'ungrounded_count' => \Illuminate\Support\Facades\DB::table('messages as m')->selectRaw('count(*)')->whereColumn('m.conversation_id', 'conversations.id')->where('m.role', Message::ASSISTANT)->where('m.meta->grounded', false),
                    'down_count' => \Illuminate\Support\Facades\DB::table('messages as m')->selectRaw('count(*)')->whereColumn('m.conversation_id', 'conversations.id')->where('m.meta->feedback', 'down'),
                    'leads_count' => \Illuminate\Support\Facades\DB::table('leads')->selectRaw('count(*)')->whereColumn('leads.conversation_id', 'conversations.id'),
                    'first_text' => \Illuminate\Support\Facades\DB::table('messages as m')->select('m.content')->whereColumn('m.conversation_id', 'conversations.id')->where('m.role', Message::USER)->orderBy('m.id')->limit(1),
                ])
                ->orderByDesc('conversations.last_message_at')->limit(5000)
                ->get()->each(function (Conversation $c) use ($out) {
                    $row = [
                        $c->id, $c->last_message_at?->format('Y-m-d H:i'), $c->workspace?->name, $c->bot?->name,
                        ChatFilters::CHANNELS[$c->channel] ?? $c->channel, ChatFilters::STATUSES[$c->status] ?? $c->status,
                        $c->contact_name ?: ChatQuery::maskPhone($c->contact_phone),
                        $c->messages_count, $c->ungrounded_count, $c->down_count, $c->leads_count,
                        Str::limit(str_replace(["\r", "\n"], ' ', (string) $c->first_text), 160, '…'),
                    ];
                    // Une cellule qui commence par = + - @ serait lue comme une formule par le tableur : on la neutralise.
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), ';');
                });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function toggle(Request $request, int $id, string $kind, string $action, string $on, string $off): RedirectResponse
    {
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($id);
        $existing = ConversationNote::where('conversation_id', $conversation->id)->where('kind', $kind);

        if ($existing->exists()) {
            $existing->delete();
            $message = $off;
        } else {
            ConversationNote::create(['conversation_id' => $conversation->id, 'user_id' => $request->user()->id, 'kind' => $kind]);
            $message = $on;
        }
        AuditLog::record($action, $this->subject($conversation), ['conversation' => $conversation->id, 'set' => $message === $on], $conversation->workspace_id);

        return back()->with('status', $message);
    }

    private function subject(Conversation $conversation, ?Bot $bot = null): string
    {
        $bot ??= Bot::withoutGlobalScopes()->find($conversation->bot_id);

        return 'Conversation n° '.$conversation->id.' ('.($bot?->name ?? 'assistant supprimé').')';
    }

    /** Le retour à la liste garde la recherche en cours : seulement un lien de cette page, jamais une adresse extérieure. */
    private function backUrl(Request $request): string
    {
        $previous = (string) $request->headers->get('referer');
        $list = route('admin.chats.index');

        return str_starts_with($previous, $list) ? $previous : $list;
    }
}
