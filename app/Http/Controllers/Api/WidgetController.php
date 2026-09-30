<?php

namespace App\Http\Controllers\Api;

use App\Chat\ChatService;
use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        return response()->json($this->bot($request)->publicConfig());
    }

    /**
     * Demarre ou reprend la conversation d'un visiteur (identifie par un id genere dans son navigateur).
     */
    public function start(Request $request): JsonResponse
    {
        $bot = $this->bot($request);

        $data = $request->validate([
            'visitor_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $conversation = Conversation::withoutGlobalScopes()
            ->where('bot_id', $bot->id)
            ->where('channel', 'web')
            ->where('external_id', $data['visitor_id'])
            ->where('status', '!=', Conversation::CLOSED)
            ->latest('id')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::withoutGlobalScopes()->create([
                'workspace_id' => $bot->workspace_id,
                'bot_id' => $bot->id,
                'channel' => 'web',
                'external_id' => $data['visitor_id'],
                'contact_name' => $data['name'] ?? null,
                'contact_email' => $data['email'] ?? null,
                'contact_phone' => $data['phone'] ?? null,
                'meta' => ['origin' => $request->headers->get('Origin'), 'user_agent' => Str::limit((string) $request->userAgent(), 200, '')],
            ]);
        }

        return response()->json([
            'token' => $conversation->token,
            'status' => $conversation->status,
            'messages' => $conversation->messages()
                ->whereIn('role', [Message::USER, Message::ASSISTANT, Message::AGENT])
                ->orderBy('id')->limit(100)->get()
                ->map->toWidget()->values(),
        ]);
    }

    public function send(Request $request, ChatService $chat, string $publicKey, string $token): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:2000']]);

        $conversation = $this->conversation($request, $token);

        // Une reponse IA peut prendre plusieurs secondes : on leve la limite PHP pour cette requete.
        @set_time_limit(120);

        $reply = $chat->handleUserMessage($conversation, $data['content'], [
            'origin' => $request->headers->get('Origin'),
        ]);

        return response()->json([
            'message' => $reply?->toWidget(),
            'status' => $conversation->fresh()->status,
        ]);
    }

    /** Le widget interroge cette route pour recevoir les reponses d'un conseiller humain. */
    public function poll(Request $request, string $publicKey, string $token): JsonResponse
    {
        $conversation = $this->conversation($request, $token);
        $after = (int) $request->query('after', 0);

        return response()->json([
            'status' => $conversation->status,
            'messages' => $conversation->messages()
                ->where('id', '>', $after)
                ->whereIn('role', [Message::ASSISTANT, Message::AGENT])
                ->orderBy('id')->limit(50)->get()
                ->map->toWidget()->values(),
        ]);
    }

    private function bot(Request $request): Bot
    {
        return $request->attributes->get('bot');
    }

    /** Le token n'ouvre que les conversations de CET assistant. */
    private function conversation(Request $request, string $token): Conversation
    {
        return Conversation::withoutGlobalScopes()
            ->where('bot_id', $this->bot($request)->id)
            ->where('token', $token)
            ->firstOrFail();
    }
}
