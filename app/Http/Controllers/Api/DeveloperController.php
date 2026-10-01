<?php

namespace App\Http\Controllers\Api;

use App\Chat\ChatService;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API des developpeurs : l'assistant d'une entreprise, appele depuis leur propre application.
 * Chaque reponse compte dans le quota mensuel de l'offre, comme sur le site ou WhatsApp.
 */
class DeveloperController extends Controller
{
    /** Pose une question a l'assistant. Une meme « conversation_id » garde le fil de la discussion. */
    public function chat(Request $request, ChatService $chat, UsageService $usage): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'user' => ['nullable', 'string', 'max:120'],
        ]);

        $bot = $request->attributes->get('bot');
        $workspace = $request->attributes->get('workspace');

        if (! $usage->canReply($workspace)) {
            return response()->json(['error' => [
                'code' => 'quota_exceeded',
                'message' => 'Le quota de réponses du mois est atteint : changez d\'offre ou attendez le mois prochain.',
            ], 'usage' => $this->usageBlock($usage, $workspace)], 429);
        }

        $externalId = $data['conversation_id'] ?? Str::random(24);

        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            ['bot_id' => $bot->id, 'channel' => 'api', 'external_id' => $externalId],
            ['workspace_id' => $bot->workspace_id, 'contact_name' => $data['user'] ?? null, 'status' => Conversation::BOT],
        );

        // Une reponse IA peut prendre plusieurs secondes : on leve la limite PHP pour cette requete.
        \App\Support\Runtime::allowLongRequest();

        $reply = $chat->handleUserMessage($conversation, $data['message'], ['channel' => 'api']);
        $conversation = $conversation->fresh();

        return response()->json([
            'conversation_id' => $externalId,
            'answer' => $reply?->content,
            'grounded' => $reply ? (bool) ($reply->meta['grounded'] ?? false) : null,
            'handoff' => in_array($conversation->status, [Conversation::NEEDS_HUMAN, Conversation::HUMAN], true),
            'suggestions' => $reply ? array_values($reply->meta['suggestions'] ?? []) : [],
            'sources' => $reply ? $this->sources($reply) : [],
            'usage' => $this->usageBlock($usage, $workspace->fresh()),
        ]);
    }

    /** Consommation du mois : reponses utilisees et quota de l'offre. */
    public function usage(Request $request, UsageService $usage): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');

        return response()->json([
            'plan' => $workspace->planModel()?->name,
            'usage' => $this->usageBlock($usage, $workspace),
            'assistant' => ['name' => $request->attributes->get('bot')->name],
        ]);
    }

    /** @return array{answers_used:int, answers_limit:int, resets_at:string} */
    private function usageBlock(UsageService $usage, $workspace): array
    {
        return [
            'answers_used' => $usage->messagesThisMonth($workspace),
            'answers_limit' => $workspace->limit('messages_per_month'),
            'resets_at' => now()->addMonthNoOverflow()->startOfMonth()->toIso8601String(),
        ];
    }

    /** @return list<array{title:?string,url:string}> */
    private function sources(Message $reply): array
    {
        return $reply->toWidget()['sources'];
    }
}
