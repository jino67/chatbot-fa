<?php

namespace App\Http\Controllers\Api;

use App\Chat\ChatService;
use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Speech\VoiceMessages;
use App\Speech\VoiceRefused;
use App\Speech\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        $data = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            // Langue choisie dans le sélecteur du widget : une des langues de l'assistant.
            'lang' => ['nullable', 'string', Rule::in($this->bot($request)->spokenLanguages())],
        ]);

        $conversation = $this->conversation($request, $token);

        // Une reponse IA peut prendre plusieurs secondes : on leve la limite PHP pour cette requete.
        \App\Support\Runtime::allowLongRequest();

        $reply = $chat->handleUserMessage($conversation, $data['content'], array_filter([
            'origin' => $request->headers->get('Origin'),
            'lang' => $data['lang'] ?? null,
        ]));

        return response()->json([
            'message' => $reply?->toWidget(),
            'status' => $conversation->fresh()->status,
        ]);
    }

    /**
     * Message vocal du visiteur : transcrit, traite comme un message ecrit, et repondu en audio si le reglage
     * de l'assistant le demande (le texte de la reponse est toujours renvoye).
     */
    public function voice(Request $request, ChatService $chat, VoiceService $voice, string $publicKey, string $token): JsonResponse
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:8192'],
            'lang' => ['nullable', 'string', Rule::in($this->bot($request)->spokenLanguages())],
        ]);

        $bot = $this->bot($request);
        $conversation = $this->conversation($request, $token);
        $file = $request->file('audio');

        \App\Support\Runtime::allowLongRequest();

        try {
            $heard = $voice->listen($bot, (string) file_get_contents($file->getRealPath()), (string) ($file->getMimeType() ?: 'audio/webm'));
        } catch (VoiceRefused $e) {
            return response()->json(['error' => $e->reason, 'message' => VoiceMessages::forRefusal($e->reason, $bot->language)], 422);
        }

        $reply = $chat->handleUserMessage($conversation, $heard->text, array_filter([
            'origin' => $request->headers->get('Origin'), 'voice' => true, 'voice_seconds' => $heard->seconds, 'lang' => $request->input('lang'),
        ]));

        $audio = $reply && $voice->wantsAudioReply($bot, true) ? $voice->speak($bot, $reply->content) : null;
        if ($audio) {
            $reply->forceFill(['meta' => array_merge($reply->meta ?? [], ['voice_reply' => true, 'voice_seconds' => $audio->seconds])])->save();
        }

        return response()->json([
            'transcript' => $heard->text,
            'message' => $reply?->toWidget(),
            'audio' => $audio?->dataUri(),
            'status' => $conversation->fresh()->status,
        ]);
    }

    /** « Ecouter » sous une reponse : lit a voix haute un message de l'assistant (compte dans le volume vocal). */
    public function speak(Request $request, VoiceService $voice, string $publicKey, string $token): JsonResponse
    {
        $data = $request->validate(['message_id' => ['required', 'integer']]);

        $bot = $this->bot($request);
        $conversation = $this->conversation($request, $token);
        $message = $conversation->messages()->whereIn('role', [Message::ASSISTANT, Message::AGENT])->findOrFail($data['message_id']);

        if (! $voice->capabilities($bot)['speak']) {
            return response()->json(['error' => 'disabled', 'message' => VoiceMessages::get('disabled', $bot->language)], 422);
        }

        $audio = $voice->speak($bot, $message->content);

        return $audio
            ? response()->json(['audio' => $audio->dataUri()])
            : response()->json(['error' => 'unavailable', 'message' => "L'écoute n'est pas disponible pour le moment."], 422);
    }

    /** Pouce leve ou baisse sur une reponse : sert a repérer les reponses a corriger dans le tableau de bord. */
    public function feedback(Request $request, string $publicKey, string $token, int $message): JsonResponse
    {
        $data = $request->validate(['value' => ['nullable', 'in:up,down']]);

        $conversation = $this->conversation($request, $token);
        $row = $conversation->messages()->where('role', Message::ASSISTANT)->findOrFail($message);

        $meta = $row->meta ?? [];
        if (($data['value'] ?? null) === null) {
            unset($meta['feedback']);
        } else {
            $meta['feedback'] = $data['value'];
        }
        $row->forceFill(['meta' => $meta ?: null])->save();

        return response()->json(['ok' => true]);
    }

    /** « Nouvelle conversation » : la conversation en cours est close, la suivante repart de zero. */
    public function close(Request $request, string $publicKey, string $token): JsonResponse
    {
        $this->conversation($request, $token)->forceFill(['status' => Conversation::CLOSED])->save();

        return response()->json(['ok' => true]);
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
