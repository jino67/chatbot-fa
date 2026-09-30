<?php

namespace App\Http\Controllers;

use App\Chat\ChatService;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Zone d'essai : le client teste son assistant, avec en plus les extraits utilises (aide au reglage). */
class PlaygroundController extends Controller
{
    public function show(Request $request, Bot $bot)
    {
        $conversation = $this->conversation($request, $bot, create: false);

        return view('playground.show', [
            'bot' => $bot,
            'history' => $conversation
                ? $conversation->messages()->whereIn('role', [Message::USER, Message::ASSISTANT])->orderBy('id')->get()
                : collect(),
        ]);
    }

    public function ask(Request $request, Bot $bot, ChatService $chat): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $conversation = $this->conversation($request, $bot);

        @set_time_limit(120);
        $reply = $chat->handleUserMessage($conversation, $data['message']);

        return response()->json([
            'reply' => $reply?->content,
            'grounded' => $reply?->meta['grounded'] ?? null,
            'top_score' => $reply?->meta['top_score'] ?? null,
            'latency_ms' => $reply?->meta['latency_ms'] ?? null,
            'reason' => $reply?->meta['reason'] ?? null,
            'sources' => $reply?->sources ?? [],
        ]);
    }

    public function reset(Request $request, Bot $bot): RedirectResponse
    {
        $this->conversation($request, $bot, create: false)?->update(['status' => Conversation::CLOSED]);

        return redirect()->route('playground.show', $bot);
    }

    private function conversation(Request $request, Bot $bot, bool $create = true): ?Conversation
    {
        $query = Conversation::where('bot_id', $bot->id)
            ->where('channel', 'playground')
            ->where('external_id', 'user-'.$request->user()->id)
            ->where('status', '!=', Conversation::CLOSED);

        return $query->first() ?? ($create ? Conversation::create([
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'channel' => 'playground',
            'external_id' => 'user-'.$request->user()->id,
            'contact_name' => $request->user()->name,
        ]) : null);
    }
}
