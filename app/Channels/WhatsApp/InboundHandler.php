<?php

namespace App\Channels\WhatsApp;

use App\Chat\ChatService;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\QueryException;

/**
 * Traite un message WhatsApp entrant, independamment du fournisseur :
 * deduplication -> conversation -> ChatService -> envoi de la reponse.
 */
class InboundHandler
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly GatewayFactory $gateways,
    ) {}

    public function handle(Channel $channel, InboundMessage $inbound): void
    {
        if (! $channel->isActive()) {
            return;
        }

        $bot = $channel->bot()->withoutGlobalScopes()->first();
        if (! $bot) {
            return;
        }

        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            ['bot_id' => $bot->id, 'channel' => 'whatsapp', 'external_id' => $inbound->from],
            ['workspace_id' => $bot->workspace_id, 'status' => Conversation::BOT],
        );

        // Meta et Twilio re-livrent un webhook si notre reponse tarde : on ne traite jamais deux fois le meme message.
        if ($conversation->messages()->where('provider_message_id', $inbound->providerMessageId)->exists()) {
            return;
        }

        $conversation->forceFill([
            'contact_name' => $conversation->contact_name ?: $inbound->name,
            'contact_phone' => '+'.$inbound->from,
        ])->save();

        $gateway = $this->gateways->for($channel);

        try {
            $gateway->markRead($inbound->providerMessageId);
        } catch (\Throwable) {
            // l'accuse de lecture est un confort, jamais une raison d'echouer
        }

        try {
            if ($inbound->type !== 'text' || ! $inbound->text) {
                $this->handleUnsupported($conversation, $gateway, $inbound);

                return;
            }

            $reply = $this->chat->handleUserMessage($conversation, $inbound->text, ['channel' => 'whatsapp'], $inbound->providerMessageId);
        } catch (QueryException $e) {
            // Course entre deux livraisons du meme webhook : l'index unique a deja tranche.
            return;
        }

        if ($reply) {
            $this->deliver($gateway, $conversation, $reply);
        }
    }

    /** Envoie un message deja enregistre (reponse du bot ou d'un conseiller) et garde la trace de la livraison. */
    public function deliver(WhatsAppGateway $gateway, Conversation $conversation, Message $message): void
    {
        try {
            $providerId = null;
            $parts = WhatsAppFormatter::parts($message->content);
            // Les reponses rapides deviennent des boutons WhatsApp, sur le dernier message seulement.
            $buttons = array_values($message->meta['suggestions'] ?? []);

            foreach ($parts as $i => $part) {
                $providerId = $gateway->sendText($conversation->external_id, $part, $i === array_key_last($parts) ? $buttons : []);
            }

            $message->forceFill(['provider_message_id' => $providerId ?: null])->save();
        } catch (\Throwable $e) {
            report($e);
            $message->forceFill(['meta' => array_merge($message->meta ?? [], ['delivery_error' => mb_substr($e->getMessage(), 0, 300)])])->save();
        }
    }

    private function handleUnsupported(Conversation $conversation, WhatsAppGateway $gateway, InboundMessage $inbound): void
    {
        $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id,
            'role' => Message::USER,
            'content' => '['.$inbound->type.']',
            'provider_message_id' => $inbound->providerMessageId,
        ]);
        $conversation->forceFill(['last_message_at' => now(), 'last_inbound_at' => now()])->save();

        if ($conversation->isHandledByHuman()) {
            return;
        }

        $reply = $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id,
            'role' => Message::ASSISTANT,
            'content' => 'Je ne peux lire que les messages écrits pour le moment. Pouvez-vous reformuler votre demande en texte ?',
            'meta' => ['grounded' => true, 'llm' => false, 'reason' => 'unsupported_media'],
        ]);

        $this->deliver($gateway, $conversation, $reply);
    }
}
