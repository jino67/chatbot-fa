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
        // Une commande en cours : le client répond aux questions de l'assistant (nom, quartier, paiement). Ce message ne dit rien du
        // produit ; la recherche reprend donc ce que l'assistant vient de dire, et le modèle sait qu'il ne s'agit pas d'une question
        // sans réponse (sinon il répond « je ne peux pas vous aider » faute d'extrait).
        $order = $image ? null : $this->orderInProgress($conversation, $userMessage);
        $query = ($order ? Text::limit($order, 200).' '.$this->recentCustomerText($conversation, $userMessage).' ' : '').$this->searchQuery($conversation, $userMessage).($image ? ' '.trim(($image['summary'] ?? '').' '.($image['details'] ?? '')) : '');
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
                ['role' => 'user', 'content' => $this->prompts->userTurn($text, $chunks, (bool) ($userMessage->meta['voice'] ?? false), $userMessage->meta['lang'] ?? null, $image, $order ? self::ORDER_NOTE : null)],
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

        // Malgré la consigne, le modèle refuse parfois en pleine commande : une seule relance, avec le rappel en tête.
        if ($order && preg_match('/ne peux pas vous aider|ne trouve pas d.informations|je n.ai pas cette information/iu', $parsed['text'])) {
            try {
                $retry = $this->llm->complete(new LlmRequest(
                    system: $request->system,
                    messages: [...$request->messages, ['role' => 'assistant', 'content' => $parsed['text']], ['role' => 'user', 'content' => "Rappel : tu es en train de prendre la commande de ce client, et son dernier message répond à tes questions. Accepte ce qu'il vient de donner, reprends ce qu'il t'a déjà dit, et pose la question suivante ou fais le récapitulatif. Ne refuse pas."]],
                ));
                if (! $retry->refused && trim($retry->text) !== '') {
                    $response = $retry;
                    $parsed = $this->prompts->parse($retry->text);
                }
            } catch (LlmException $e) {
                report($e);
            }
        }

        if ($order) {
            $parsed['no_answer'] = false; // une réponse de commande n'est pas une question sans réponse
        }

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
            $contact = $this->contactGiven($conversation, $parsed['lead'], $userMessage);
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

    private const ORDER_NOTE = "tu es en train de prendre la commande de ce client. Son message répond à ta dernière question (nom, quartier, paiement, quantité, numéro, « oui ») : accepte-le, reprends tout ce qu'il t'a déjà donné plus haut, puis pose la question suivante ou fais le récapitulatif. Ce n'est pas une question sur l'entreprise : ne refuse pas et n'ajoute pas [[NO_ANSWER]].";

    /** Ce que le client a demandé juste avant (le produit dont il parle), pour retrouver sa fiche pendant une commande. */
    private function recentCustomerText(Conversation $conversation, Message $current): string
    {
        return $conversation->messages()->where('role', Message::USER)->where('id', '<', $current->id)->latest('id')->limit(3)->pluck('content')
            ->map(fn ($text) => Text::limit($text, 150))->reverse()->implode(' ');
    }

    /**
     * Le dernier message de l'assistant demandait-il des informations de commande (nom, quartier, paiement, quantité...) ? Un
     * message du client sans point d'interrogation est alors sa réponse, et non une question sur l'entreprise. Rend le texte de
     * l'assistant (pour retrouver le produit), ou null.
     */
    private function orderInProgress(Conversation $conversation, Message $current): ?string
    {
        if (str_contains($current->content, '?')) {
            return null;
        }

        $last = $conversation->messages()->where('role', Message::ASSISTANT)->where('id', '<', $current->id)->latest('id')->first();
        if (! $last || ! str_contains($last->content, '?')) {
            return null;
        }

        return preg_match('/\b(votre nom|quartier|adresse de livraison|moyen de paiement|mode de paiement|quantite|numero de telephone|votre numero|passer commande|commander)\b/u', Text::fold($last->content)) ? $last->content : null;
    }

    /**
     * Nom et téléphone que le client a donnés pour sa commande. Sur WhatsApp le numéro est déjà connu (celui de la
     * conversation) : on ne le remplace jamais par un numéro écrit par le modèle. Sur le site web, un numéro donné est
     * gardé sur la conversation, pour que le propriétaire puisse rappeler depuis la boîte de réception.
     *
     * @param  array{kind:string,summary:string,name:?string,phone:?string}  $lead
     * @return array{name:?string, phone:?string}
     */
    private function contactGiven(Conversation $conversation, array $lead, Message $current): array
    {
        $phone = $conversation->channel === 'whatsapp' ? null : $lead['phone'];

        // Le modèle met parfois le numéro de l'entreprise (lu dans ses consignes) à la place de celui du client : refusé. Le numéro que
        // le client a écrit lui-même, dans ce message ou les précédents, est alors celui qu'on garde.
        if ($phone && $this->isBusinessNumber($conversation, $phone)) {
            $phone = null;
        }
        if ($conversation->channel !== 'whatsapp' && ! $phone) {
            $phone = $this->phoneWrittenByCustomer($conversation, $current);
        }

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

    /** Le numéro est-il l'un de ceux de l'entreprise (sa fiche, ses consignes, la plateforme) ? Comparaison sur les 8 derniers chiffres. */
    private function isBusinessNumber(Conversation $conversation, string $phone): bool
    {
        $bot = $conversation->bot()->withoutGlobalScopes()->with('workspace')->first();
        $brand = app(\App\Services\PlatformSettings::class)->brand();
        $sources = [(string) ($bot?->profile('phone') ?? ''), (string) ($bot?->workspace?->phone ?? ''), (string) ($bot?->instructions ?? ''), (string) ($brand['whatsapp'] ?? '')];

        $business = [];
        foreach ($sources as $source) {
            if (preg_match_all('/\+?\d[\d\s().-]{6,18}\d/', $source, $m)) {
                foreach ($m[0] as $found) {
                    $business[] = substr(preg_replace('/\D+/', '', $found), -8);
                }
            }
        }

        return in_array(substr(preg_replace('/\D+/', '', $phone), -8), $business, true);
    }

    /** Le numéro que le client a écrit lui-même (message courant, puis les trois précédents), hors numéros de l'entreprise. */
    private function phoneWrittenByCustomer(Conversation $conversation, Message $current): ?string
    {
        $texts = [$current->content, ...$conversation->messages()->where('role', Message::USER)->where('id', '<', $current->id)->latest('id')->limit(3)->pluck('content')->all()];

        foreach ($texts as $text) {
            if (preg_match_all('/\+?\d[\d\s().-]{6,18}\d/', (string) $text, $m)) {
                foreach (array_reverse($m[0]) as $found) {
                    $digits = preg_replace('/\D+/', '', $found);
                    if (strlen($digits) >= 8 && strlen($digits) <= 15 && ! $this->isBusinessNumber($conversation, $found)) {
                        return (str_starts_with(trim($found), '+') ? '+' : '').$digits;
                    }
                }
            }
        }

        return null;
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
