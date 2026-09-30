<?php

namespace App\Chat;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmRequest;
use App\Ai\LlmException;
use App\Mail\HandoffRequested;
use App\Models\Conversation;
use App\Models\Message;
use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;
use App\Services\UsageService;
use App\Support\Text;
use Illuminate\Support\Facades\Mail;

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
            return null;
        }

        return $this->reply($conversation, $userMessage);
    }

    /** Produit la reponse du bot a un message deja enregistre. */
    public function reply(Conversation $conversation, Message $userMessage): Message
    {
        $started = microtime(true);
        $bot = $conversation->bot()->with('workspace')->first();
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
                ['grounded' => false, 'llm' => false, 'reason' => $bot->workspace->is_suspended ? 'workspace_suspended' : 'quota_exceeded']
            );
        }

        $smallTalk = Text::isSmallTalk($text);
        $chunks = $smallTalk ? [] : $this->retriever->retrieve($bot, $this->searchQuery($conversation, $userMessage));

        // Hors salutation, sans extrait pertinent : on ne depense pas un appel LLM, on avoue ne pas savoir.
        // Exception : une reclamation ou une urgence merite une reponse empathique et un possible transfert.
        if (! $smallTalk && $chunks === [] && ! Text::isSensitive($text)) {
            return $this->miss($conversation, $bot->fallback(), ['llm' => false, 'top_score' => 0.0, 'reason' => 'no_context']);
        }

        $request = new LlmRequest(
            system: $this->prompts->system($bot, $conversation->channel),
            messages: [
                ...$this->prompts->history($conversation, $userMessage->id),
                ['role' => 'user', 'content' => $this->prompts->userTurn($text, $chunks)],
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
        $grounded = ! $parsed['no_answer'] && ($smallTalk || $chunks !== []);

        $meta = [
            'grounded' => $grounded,
            'llm' => true,
            // Fournisseur qui a reellement repondu (apres une eventuelle bascule).
            'provider' => $response->provider ?? $this->llm->name(),
            'model' => $response->model,
            'top_score' => $chunks ? round($chunks[0]->score, 3) : 0.0,
            'retrieved' => count($chunks),
            'tokens' => ['in' => $response->inputTokens, 'out' => $response->outputTokens, 'cache_read' => $response->cacheReadTokens],
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ];

        // Reponses rapides proposees au visiteur (touches du widget, boutons WhatsApp).
        if ($parsed['suggestions'] && $grounded && ! $parsed['handoff']) {
            $meta['suggestions'] = $parsed['suggestions'];
        }

        if ($parsed['handoff']) {
            $this->requestHuman($conversation, 'demande du client ou situation sensible');
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

    public function requestHuman(Conversation $conversation, string $reason): void
    {
        if ($conversation->isHandledByHuman()) {
            return;
        }

        $conversation->forceFill([
            'status' => Conversation::NEEDS_HUMAN,
            'meta' => array_merge($conversation->meta ?? [], ['handoff_reason' => $reason, 'handoff_at' => now()->toIso8601String()]),
        ])->save();

        $email = $conversation->bot?->handoff_email;
        if ($email) {
            try {
                Mail::to($email)->queue(new HandoffRequested($conversation, $reason));
            } catch (\Throwable $e) {
                report($e); // une notification perdue ne doit jamais casser la conversation
            }
        }
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
