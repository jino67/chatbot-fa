<?php

namespace App\Chat;

use App\Ai\LlmException;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\UsageService;
use App\Support\ImageTools;
use App\Support\Text;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La photo d'un client (WhatsApp ou chat du site) : vérifiée, allégée et débarrassée de ses métadonnées, gardée en privé
 * pour le propriétaire (effacée après `platform.vision.retention_days` jours), lue par le modèle de vision avec le brief du
 * métier, puis traitée comme un message : l'assistant répond en connaissant ce que la photo montre. Une photo sensible
 * (pièce d'identité, carte bancaire) n'est jamais conservée.
 */
class CustomerImages
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly VisionAnalyzer $vision,
        private readonly UsageService $usage,
    ) {}

    /**
     * @param  array<string,mixed>  $meta  informations du canal (origine, langue...)
     * @return Message|null la réponse de l'assistant (null si une personne a la main)
     */
    public function receive(Conversation $conversation, string $bytes, ?string $caption = null, array $meta = [], ?string $providerMessageId = null): ?Message
    {
        $bot = Bot::withoutGlobalScopes()->with('workspace')->find($conversation->bot_id);
        $caption = $this->caption($caption);

        if (! $bot || ! $bot->acceptsImages()) {
            return $this->decline($conversation, $caption, 'images_off', $bot, $meta, $providerMessageId,
                "Je ne peux lire que les messages écrits pour le moment. Pouvez-vous me décrire votre demande en quelques mots ?");
        }
        if ($this->tooMany($conversation)) {
            return $this->decline($conversation, $caption, 'images_cap', $bot, $meta, $providerMessageId,
                "J'ai déjà reçu beaucoup de photos aujourd'hui. Décrivez-moi votre demande par écrit, ou l'équipe pourra regarder vos photos avec vous.");
        }

        $jpeg = ImageTools::jpeg($bytes, (int) config('platform.vision.max_side'));
        if ($jpeg === null) {
            return $this->decline($conversation, $caption, 'image_unreadable', $bot, $meta, $providerMessageId,
                "Je n'ai pas réussi à ouvrir cette photo. Pouvez-vous la renvoyer, ou me décrire en quelques mots ce qu'elle montre ?");
        }

        $image = ['category' => 'autre', 'summary' => '', 'details' => '', 'sensitive' => false, 'failed' => false];

        // Pas d'appel au modèle quand une personne a la main (elle regarde la photo elle-même) ou quand le volume de l'offre est atteint.
        $human = $conversation->isHandledByHuman();
        if (! $human && $bot->is_active && $this->usage->canReply($bot->workspace)) {
            try {
                $image = array_replace($image, $this->vision->analyze($bot, $jpeg['bytes'], $caption));
            } catch (LlmException $e) {
                report($e);
                $image['failed'] = true;
            }
        }

        // Une pièce d'identité ou une carte bancaire ne reste jamais chez nous.
        if (! $image['sensitive']) {
            $path = 'chat-images/'.$conversation->workspace_id.'/'.$conversation->id.'/'.Str::uuid().'.jpg';
            $this->disk()->put($path, $jpeg['bytes']);
            $image['path'] = $path;
        }

        return $this->chat->handleUserMessage($conversation, $caption !== '' ? $caption : '[Photo]', $meta + ['image' => $image], $providerMessageId);
    }

    /** Efface les photos conservées au-delà de la durée prévue (la description écrite reste). */
    public function prune(): int
    {
        $limit = now()->subDays((int) config('platform.vision.retention_days'));
        $removed = 0;

        Message::withoutGlobalScopes()->where('role', Message::USER)->whereNotNull('meta->image->path')->where('created_at', '<', $limit)
            ->orderBy('id')->chunkById(200, function ($messages) use (&$removed) {
                foreach ($messages as $message) {
                    $meta = $message->meta;
                    $this->disk()->delete($meta['image']['path']);
                    unset($meta['image']['path']);
                    $meta['image']['expired'] = true;
                    $message->forceFill(['meta' => $meta])->save();
                    $removed++;
                }
            });

        return $removed;
    }

    private function caption(?string $caption): string
    {
        $caption = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $caption) ?? '';

        return Text::limit(trim($caption), 500);
    }

    /** Dix photos par jour et par conversation : au-delà, c'est un abus plus qu'une demande. */
    private function tooMany(Conversation $conversation): bool
    {
        return $conversation->messages()->where('role', Message::USER)->whereNotNull('meta->image')
            ->where('created_at', '>=', now()->subDay())->count() >= (int) config('platform.vision.daily_cap');
    }

    /** Le message du client est gardé, l'assistant répond une phrase fixe (aucun appel au modèle). */
    private function decline(Conversation $conversation, string $caption, string $reason, ?Bot $bot, array $meta, ?string $providerMessageId, string $answer): ?Message
    {
        $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id, 'role' => Message::USER, 'content' => $caption !== '' ? $caption : '[Photo]',
            'meta' => $meta + ['image' => ['declined' => $reason]] ?: null, 'provider_message_id' => $providerMessageId,
        ]);
        $conversation->forceFill(['last_message_at' => now(), 'last_inbound_at' => now()])->save();

        if ($conversation->isHandledByHuman()) {
            return null;
        }

        $reply = $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id, 'role' => Message::ASSISTANT, 'content' => $answer,
            'meta' => ['grounded' => true, 'llm' => false, 'reason' => $reason],
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();

        return $reply;
    }

    private function disk()
    {
        return Storage::disk(config('platform.uploads.disk'));
    }
}
