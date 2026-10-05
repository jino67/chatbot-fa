<?php

namespace App\Channels\WhatsApp;

use App\Chat\ChatService;
use App\Chat\CustomerImages;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\UsageMeter;
use App\Services\UsageService;
use App\Speech\VoiceMessages;
use App\Speech\VoiceRefused;
use App\Speech\VoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Traite un message WhatsApp entrant, independamment du fournisseur :
 * deduplication -> conversation -> ChatService -> envoi de la reponse.
 */
class InboundHandler
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly GatewayFactory $gateways,
        private readonly UsageMeter $meter,
        private readonly UsageService $usage,
        private readonly VoiceService $voice,
        private readonly CustomerImages $images,
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

        $voiceNote = false;

        try {
            if ($inbound->type === 'audio' && ! $inbound->text) {
                $this->meter->whatsapp($channel, 'in', null, $bot->id);
                $heard = $this->hear($bot, $gateway, $inbound);

                if (is_string($heard)) {
                    // La voix ne peut pas servir : le client est prevenu et invite a ecrire.
                    $this->handleUnsupported($conversation, $gateway, $inbound, $heard);

                    return;
                }

                $voiceNote = true;
                $reply = $this->chat->handleUserMessage($conversation, $heard->text, [
                    'channel' => 'whatsapp', 'voice' => true, 'voice_seconds' => $heard->seconds,
                ], $inbound->providerMessageId);
            } elseif ($inbound->type === 'image' && $inbound->mediaRef) {
                // Photo du client : lue par le modèle de vision avec le brief du métier de l'entreprise.
                $this->meter->whatsapp($channel, 'in', null, $bot->id);
                $reply = $this->receiveImage($conversation, $gateway, $inbound);
            } elseif ($inbound->type !== 'text' || ! $inbound->text) {
                $this->meter->whatsapp($channel, 'in', null, $bot->id);
                $this->handleUnsupported($conversation, $gateway, $inbound);

                return;
            } else {
                $reply = $this->chat->handleUserMessage($conversation, $inbound->text, ['channel' => 'whatsapp'], $inbound->providerMessageId);
                $this->meter->whatsapp($channel, 'in', null, $bot->id);
            }
        } catch (QueryException $e) {
            // Course entre deux livraisons du meme webhook : l'index unique a deja tranche.
            return;
        }

        if ($reply) {
            $this->deliver($gateway, $conversation, $reply, $voiceNote);
        }
    }

    /** Télécharge la photo et la confie à CustomerImages ; si elle ne s'ouvre pas, le client est invité à la renvoyer. */
    private function receiveImage(Conversation $conversation, WhatsAppGateway $gateway, InboundMessage $inbound): ?Message
    {
        try {
            $media = $gateway->downloadMedia($inbound);
        } catch (\Throwable $e) {
            report($e);
            $media = null;
        }

        if ($media === null || strlen($media['bytes']) > (int) config('platform.vision.max_bytes')) {
            $this->handleUnsupported($conversation, $gateway, $inbound);

            return null;
        }

        return $this->images->receive($conversation, $media['bytes'], $inbound->text, ['channel' => 'whatsapp'], $inbound->providerMessageId);
    }

    /**
     * Telecharge et transcrit le message vocal.
     *
     * @return \App\Speech\Transcription|string la transcription, ou la raison du refus (disabled, quota, engine, too_long, unclear)
     */
    private function hear(\App\Models\Bot $bot, WhatsAppGateway $gateway, InboundMessage $inbound): \App\Speech\Transcription|string
    {
        try {
            $media = $gateway->downloadMedia($inbound);

            return $this->voice->listen($bot, $media['bytes'], $media['mime']);
        } catch (VoiceRefused $e) {
            return $e->reason;
        } catch (\Throwable $e) {
            report($e);

            return 'unclear';
        }
    }

    /** Envoie un message deja enregistre (reponse du bot ou d'un conseiller) et garde la trace de la livraison. */
    public function deliver(WhatsAppGateway $gateway, Conversation $conversation, Message $message, bool $inboundWasVoice = false): void
    {
        // Les messages WhatsApp sont payes par la plateforme : le volume de l'offre (et le credit) limite l'envoi.
        $workspace = Workspace::withoutGlobalScopes()->find($conversation->workspace_id);
        if ($workspace && ! $this->usage->canSendWhatsApp($workspace)) {
            $message->forceFill(['meta' => array_merge($message->meta ?? [], ['delivery_error' => 'volume de messages WhatsApp atteint : rechargez des messages ou changez d\'offre'])])->save();
            $this->warnQuota($workspace);

            return;
        }

        // Reponse en audio selon le reglage de l'assistant ; le texte part toujours, et une panne de la voix ne l'empeche pas.
        if ($message->role === Message::ASSISTANT) {
            $this->sendVoiceReply($gateway, $conversation, $message, $inboundWasVoice);
        }

        // Photos de produits : elles partent d'abord (avec leur légende), la réponse écrite ensuite. Une photo qui ne part pas
        // ne retarde jamais la réponse.
        if ($message->role === Message::ASSISTANT && ! empty($message->meta['media'])) {
            $this->sendPhotos($gateway, $conversation, $message);
        }

        try {
            $providerId = null;
            $parts = WhatsAppFormatter::parts($message->content);
            // Les reponses rapides deviennent des boutons WhatsApp, sur le dernier message seulement.
            $buttons = array_values($message->meta['suggestions'] ?? []);

            foreach ($parts as $i => $part) {
                $providerId = $gateway->sendText($conversation->external_id, $part, $i === array_key_last($parts) ? $buttons : []);
                $this->meter->whatsapp($gateway->channel(), 'out', null, $conversation->bot_id);
            }

            $message->forceFill(['provider_message_id' => $providerId ?: null])->save();
        } catch (\Throwable $e) {
            report($e);
            $message->forceFill(['meta' => array_merge($message->meta ?? [], ['delivery_error' => mb_substr($e->getMessage(), 0, 300)])])->save();
        }
    }

    private function sendPhotos(WhatsAppGateway $gateway, Conversation $conversation, Message $message): void
    {
        $sent = 0;

        foreach (app(\App\Chat\CatalogMedia::class)->resolve($message->meta['media']) as $photo) {
            try {
                $gateway->sendImage($conversation->external_id, $photo['url'], $photo['caption']);
                $this->meter->whatsapp($gateway->channel(), 'out', null, $conversation->bot_id);
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $message->forceFill(['meta' => array_merge($message->meta ?? [], ['media_sent' => $sent])])->save();
    }

    private function sendVoiceReply(WhatsAppGateway $gateway, Conversation $conversation, Message $message, bool $inboundWasVoice): void
    {
        try {
            $bot = \App\Models\Bot::withoutGlobalScopes()->with('workspace')->find($conversation->bot_id);
            if (! $bot || ! $this->voice->wantsAudioReply($bot, $inboundWasVoice) || ($message->meta['reason'] ?? null) === 'human_requested') {
                return;
            }

            $workspace = $bot->workspace;
            $audio = $this->voice->speak($bot, $message->content);
            if (! $audio || ! $this->usage->canSendWhatsApp($workspace)) {
                return;
            }

            $gateway->sendAudio($conversation->external_id, $audio);
            $this->meter->whatsapp($gateway->channel(), 'out', null, $conversation->bot_id);
            $message->forceFill(['meta' => array_merge($message->meta ?? [], ['voice_reply' => true, 'voice_seconds' => $audio->seconds])])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Une fois par jour, le proprietaire apprend que son assistant ne peut plus repondre sur WhatsApp. */
    private function warnQuota(Workspace $workspace): void
    {
        if (! Cache::add('alert:wa-blocked:'.$workspace->id.':'.today()->format('Ymd'), 1, 86400)) {
            return;
        }

        // Centre de notifications, téléphone et e-mail au propriétaire (voir App\Notify\Events).
        app(\App\Notify\Events::class)->whatsappBlocked($workspace);
    }

    /** @param  string|null  $voiceRefusal  raison du refus d'un message vocal (disabled, quota, engine, too_long, unclear) */
    private function handleUnsupported(Conversation $conversation, WhatsAppGateway $gateway, InboundMessage $inbound, ?string $voiceRefusal = null): void
    {
        $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id,
            'role' => Message::USER,
            'content' => '['.$inbound->type.']',
            'provider_message_id' => $inbound->providerMessageId,
            'meta' => $voiceRefusal ? ['voice' => true, 'voice_refused' => $voiceRefusal] : null,
        ]);
        $conversation->forceFill(['last_message_at' => now(), 'last_inbound_at' => now()])->save();

        if ($conversation->isHandledByHuman()) {
            return;
        }

        $reply = $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id,
            'role' => Message::ASSISTANT,
            'content' => $voiceRefusal
                ? VoiceMessages::forRefusal($voiceRefusal, $conversation->bot()->withoutGlobalScopes()->first()?->language ?? 'fr')
                : ($inbound->type === 'image'
                    ? "Je n'ai pas réussi à ouvrir votre photo. Pouvez-vous la renvoyer, ou me décrire en quelques mots ce qu'elle montre ?"
                    : 'Je ne peux lire que les messages écrits pour le moment. Pouvez-vous reformuler votre demande en texte ?'),
            'meta' => ['grounded' => true, 'llm' => false, 'reason' => $voiceRefusal ? 'voice_'.$voiceRefusal : 'unsupported_media'],
        ]);

        $this->deliver($gateway, $conversation, $reply);
    }
}
