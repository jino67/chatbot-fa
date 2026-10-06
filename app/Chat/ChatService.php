<?php

namespace App\Chat;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmRequest;
use App\Ai\LlmException;
use App\Leads\LeadService;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;
use App\Services\UsageMeter;
use App\Services\UsageService;
use App\Support\Text;

/**
 * Orchestre un tour de conversation, quel que soit le canal (widget, WhatsApp, playground) :
 * garde-fous -> recherche dans la base -> LLM -> analyse des marqueurs -> stockage -> transfert humain.
 */
class ChatService
{
    public function __construct(
        private readonly Retriever $retriever,
        private readonly PromptBuilder $prompts,
        private readonly LlmClient $llm,
        private readonly UsageService $usage,
        private readonly UsageMeter $meter,
        private readonly LeadService $leads,
        private readonly CatalogMedia $media,
    ) {}

    /**
     * Enregistre le message du client puis renvoie la reponse du bot (null si un humain a la main).
     *
     * @param  array<string,mixed>  $meta
     */
    public function handleUserMessage(Conversation $conversation, string $text, array $meta = [], ?string $providerMessageId = null): ?Message
    {
        $text = $this->sanitize($text);

        $userMessage = $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id,
            'role' => Message::USER,
            'content' => $text,
            'meta' => $meta ?: null,
            'provider_message_id' => $providerMessageId,
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            // Un client qui ecrit de nouveau rouvre une conversation close.
            'status' => $conversation->status === Conversation::CLOSED ? Conversation::BOT : $conversation->status,
        ])->save();

        if ($conversation->isHandledByHuman()) {
            $this->tellTeamOfNewMessage($conversation, $text);

            return null;
        }

        return $this->reply($conversation, $userMessage);
    }

    /** Produit la reponse du bot a un message deja enregistre. */
    public function reply(Conversation $conversation, Message $userMessage): Message
    {
        $started = microtime(true);
        $bot = $conversation->bot()->withoutGlobalScopes()->with('workspace')->first();
        $text = $userMessage->content;

        // Une demande explicite de contact humain passe avant tout : ni quota, ni base de connaissances, ni appel IA.
        if (Text::wantsHuman($text)) {
            $this->requestHuman($conversation, 'demande explicite du client');

            return $this->store($conversation, $this->humanAcknowledgement($bot->language), [], [
                'grounded' => true, 'llm' => false, 'reason' => 'human_requested',
            ]);
        }

        if (! $bot->is_active) {
            return $this->store($conversation, $bot->fallback(), [], ['grounded' => false, 'llm' => false, 'reason' => 'bot_inactive']);
        }

        if (! $this->usage->canReply($bot->workspace)) {
            return $this->store(
                $conversation,
                "Ce service est momentanément indisponible. Merci de contacter directement l'entreprise.",
                [],
                ['grounded' => false, 'llm' => false, 'reason' => match (true) {
                    $bot->workspace->is_suspended => 'workspace_suspended',
                    $bot->workspace->trialExpired() => 'trial_expired',
                    default => 'quota_exceeded',
                }]
            );
        }

        // Photo envoyée par le client : sa description (lue par le modèle de vision) fait partie de sa question.
        $image = is_array($userMessage->meta['image'] ?? null) && empty($userMessage->meta['image']['declined']) ? $userMessage->meta['image'] : null;
        if ($image && ($image['failed'] ?? false)) {
            return $this->store($conversation, "Je n'arrive pas à lire cette photo pour le moment. Pouvez-vous m'écrire ce qu'elle montre ou ce dont vous avez besoin ?", [], ['grounded' => true, 'llm' => false, 'reason' => 'vision_unavailable']);
        }

        $smallTalk = ! $image && Text::isSmallTalk($text);
        $query = $this->searchQuery($conversation, $userMessage).($image ? ' '.trim(($image['summary'] ?? '').' '.($image['details'] ?? '')) : '');
        $chunks = $smallTalk ? [] : $this->retriever->retrieve($bot, $query);

        // Hors salutation, sans extrait pertinent : on ne depense pas un appel LLM, on avoue ne pas savoir.
        // Exceptions : une reclamation ou une urgence merite une reponse empathique et un possible transfert ;
        // la conversation libre (réglage de l'assistant, active par défaut) laisse le modèle répondre, le prompt gardant
        // les informations de l'entreprise tirées des seuls extraits.
        $open = $bot->allowsFreeChat() || $image !== null;
        if (! $smallTalk && ! $open && $chunks === [] && ! Text::isSensitive($text)) {
            return $this->miss($conversation, $bot->fallback(), ['llm' => false, 'top_score' => 0.0, 'reason' => 'no_context']);
        }

        $request = new LlmRequest(
            system: $this->prompts->system($bot, $conversation->channel),
            messages: [
                ...$this->prompts->history($conversation, $userMessage->id),
                ['role' => 'user', 'content' => $this->prompts->userTurn($text, $chunks, (bool) ($userMessage->meta['voice'] ?? false), $userMessage->meta['lang'] ?? null, $image)],
            ],
        );

        try {
            $response = $this->llm->complete($request);
        } catch (LlmException $e) {
            report($e);

            return $this->miss($conversation, $bot->fallback(), ['llm' => false, 'reason' => 'llm_error', 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        if ($response->refused) {
            return $this->miss($conversation, $bot->fallback(), ['llm' => true, 'reason' => 'refusal', 'model' => $response->model]);
        }

        $parsed = $this->prompts->parse($response->text);
        $answer = $parsed['text'] !== '' ? $parsed['text'] : $bot->fallback();
        $grounded = ! $parsed['no_answer'] && ($smallTalk || $open || $chunks !== []);

        $meta = [
            'grounded' => $grounded,
            'llm' => true,
            // Fournisseur qui a reellement repondu (apres une eventuelle bascule).
            'provider' => $response->provider ?? $this->llm->name(),
            'model' => $response->model,
            'top_score' => $chunks ? round($chunks[0]->score, 3) : 0.0,
            'free_chat' => $open && ! $smallTalk && $chunks === [],
            'retrieved' => count($chunks),
            'tokens' => ['in' => $response->inputTokens, 'out' => $response->outputTokens, 'cache_read' => $response->cacheReadTokens],
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ];

        // Chaque reponse de l'IA est comptee avec son cout estime (page « Consommation » du super admin).
        $this->meter->ai($conversation->workspace_id, $bot->id, $meta['provider'], $meta['model'] ?? null, (int) $response->inputTokens, (int) $response->outputTokens);

        // Reponses rapides proposees au visiteur (touches du widget, boutons WhatsApp).
        if ($parsed['suggestions'] && $grounded && ! $parsed['handoff']) {
            $meta['suggestions'] = $parsed['suggestions'];
        }

        // Le client demande une réponse en audio : la réponse l'indique, le canal l'envoie (vocal WhatsApp, lecture dans le widget).
        if (Text::wantsAudio($text)) {
            $meta['audio_requested'] = true;
        }

        // Photos des produits : celles que l'assistant propose, plus celle que le client a demandée et que l'assistant a oublié de joindre.
        if ($grounded && ! $parsed['handoff'] && ! $smallTalk) {
            $asked = Text::wantsPhoto($text);
            $refs = $parsed['photos'] !== [] || ! $asked ? $parsed['photos'] : $this->media->refsFromContext($chunks, $answer);
            $items = $this->media->pick($bot, $conversation, $refs, $asked);
            if ($items !== []) {
                $meta['media'] = $this->media->describe($items);
            }
        }

        if ($parsed['handoff']) {
            $this->requestHuman($conversation, 'demande du client ou situation sensible');
        }

        // Commande, rendez-vous ou devis confirmé par le client : une demande à traiter, et le propriétaire est prévenu.
        if ($parsed['lead'] && $grounded && ! $parsed['handoff']) {
            $contact = $this->contactGiven($conversation, $parsed['lead']);
            $this->leads->capture($conversation, $parsed['lead']['kind'], $parsed['lead']['summary'], $parsed['lead']['summary'] ?: null, $contact);
        }

        if (! $grounded && ! $parsed['handoff']) {
            return $this->miss($conversation, $answer, $meta, $chunks);
        }

        return $this->store(
            $conversation,
            $answer,
            $grounded ? array_map(fn (RetrievedChunk $c) => $c->toReference(), $chunks) : [],
            $meta
        );
    }

    /**
     * Nom et téléphone que le client a donnés pour sa commande. Sur WhatsApp le numéro est déjà connu (celui de la
     * conversation) : on ne le remplace jamais par un numéro écrit par le modèle. Sur le site web, un numéro donné est
     * gardé sur la conversation, pour que le propriétaire puisse rappeler depuis la boîte de réception.
     *
     * @param  array{kind:string,summary:string,name:?string,phone:?string}  $lead
     * @return array{name:?string, phone:?string}
     */
    private function contactGiven(Conversation $conversation, array $lead): array
    {
        $phone = $conversation->channel === 'whatsapp' ? null : $lead['phone'];

        $updates = [];
        if ($lead['name'] && ! $conversation->contact_name) {
            $updates['contact_name'] = $lead['name'];
        }
        if ($phone && ! $conversation->contact_phone) {
            $updates['contact_phone'] = $phone;
        }
        if ($updates !== []) {
            $conversation->forceFill($updates)->save();
        }

        return ['name' => $lead['name'], 'phone' => $phone];
    }

    /**
     * Le bot n'a pas su repondre. Apres deux echecs consecutifs, on passe la main a l'equipe.
     *
     * @param  array<string,mixed>  $meta
     * @param  list<RetrievedChunk>  $chunks
     */
    private function miss(Conversation $conversation, string $answer, array $meta, array $chunks = []): Message
    {
        $previous = $conversation->messages()
            ->where('role', Message::ASSISTANT)
            ->latest('id')
            ->first();

        $consecutive = $previous?->isUngrounded() ?? false;
        $handedOff = false;

        if ($consecutive && ! $conversation->isHandledByHuman()) {
            $this->requestHuman($conversation, 'deux questions sans réponse consécutives');
            $answer .= "\n\nJe transmets votre demande à l'équipe, qui vous répondra dès que possible.";
            $handedOff = true;
        }

        $meta = array_merge(['grounded' => false, 'retrieved' => count($chunks)], $meta);
        if (! $handedOff) {
            $meta['suggestions'] = $this->fallbackSuggestions($conversation);
        }

        return $this->store($conversation, $answer, [], $meta);
    }

    /** Apres un echec de reponse : proposer de parler a l'equipe, puis les questions habituelles de l'assistant. */
    private function fallbackSuggestions(Conversation $conversation): array
    {
        $own = array_slice(array_filter($conversation->bot?->suggested_questions ?? []), 0, 2);

        return array_map(fn ($s) => Text::limit($s, 24), array_merge(["Parler à l'équipe"], $own));
    }

    /** @param list<array<string,mixed>> $sources @param array<string,mixed> $meta */
    private function store(Conversation $conversation, string $content, array $sources, array $meta): Message
    {
        $message = $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id,
            'role' => Message::ASSISTANT,
            'content' => $content,
            'sources' => $sources ?: null,
            'meta' => $meta,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    /**
     * Un client écrit pendant qu'une personne a la main : l'assistant se tait, donc personne ne le saurait sans cette alerte.
     * Une seule notification toutes les cinq minutes par conversation : un client qui écrit trois phrases de suite n'en déclenche qu'une.
     */
    private function tellTeamOfNewMessage(Conversation $conversation, string $text): void
    {
        try {
            $workspace = \App\Models\Workspace::withoutGlobalScopes()->find($conversation->workspace_id);
            if (! $workspace) {
                return;
            }

            app(\App\Notify\Notifier::class)->toWorkspace(
                $workspace,
                'handoffs',
                'Nouveau message de '.$conversation->displayName(),
                Text::limit($text, 140),
                route('conversations.show', [$conversation->bot_id, $conversation->id], false),
                ['tag' => 'conversation-'.$conversation->id, 'dedupe_minutes' => 5, 'data' => ['conversation' => $conversation->id]],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function requestHuman(Conversation $conversation, string $reason): void
    {
        if ($conversation->isHandledByHuman()) {
            return;
        }

        $conversation->forceFill([
            'status' => Conversation::NEEDS_HUMAN,
            'meta' => array_merge($conversation->meta ?? [], ['handoff_reason' => $reason, 'handoff_at' => now()->toIso8601String()]),
        ])->save();

        // La demande est notée, et le propriétaire prévenu selon ses alertes (e-mail, WhatsApp) : voir LeadNotifier.
        $last = $conversation->messages()->where('role', Message::USER)->latest('id')->value('content');
        $this->leads->capture($conversation, Lead::HUMAN, $last ? "« {$last} »" : $reason, null);
    }

    private function humanAcknowledgement(string $language): string
    {
        return match ($language) {
            'en' => "Of course, I'm letting a team member know. They will reply here as soon as possible.",
            'ar' => 'بالتأكيد، سأُبلغ أحد أعضاء الفريق وسيرد عليك هنا في أقرب وقت ممكن.',
            default => "Bien sûr, je préviens un membre de l'équipe qui vous répondra ici dès que possible.",
        };
    }

    /** Une question courte ("et le prix ?") est enrichie par la question precedente pour la recherche. */
    private function searchQuery(Conversation $conversation, Message $current): string
    {
        $text = $current->content;

        if (str_word_count(Text::fold($text)) >= 4) {
            return $text;
        }

        $previous = $conversation->messages()
            ->where('role', Message::USER)
            ->where('id', '<', $current->id)
            ->latest('id')
            ->value('content');

        return $previous ? $previous.' '.$text : $text;
    }

    private function sanitize(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return Text::limit(trim($text), (int) config('platform.rag.max_message_chars'));
    }
}
