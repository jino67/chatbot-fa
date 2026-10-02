<?php

namespace App\Ai\Llm;

use App\Ai\LlmException;
use App\Mail\Notice;
use App\Models\AiProvider;
use App\Services\PlatformSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Chaine de fournisseurs de modeles : ordre, disjoncteur (mise en pause temporaire apres une panne),
 * epinglage manuel depuis le tableau de bord, et alerte quand un credit est epuise.
 */
class ProviderRegistry
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /** @return Collection<int,AiProvider> */
    public function all(): Collection
    {
        return AiProvider::query()->orderBy('priority')->orderBy('id')->get();
    }

    /** Aucun modele reel disponible : la plateforme repond avec le mode hors ligne (demonstration). */
    public function isOffline(): bool
    {
        return config('platform.ai.offline') || ! $this->all()->contains(fn (AiProvider $p) => $p->enabled && $p->isConfigured());
    }

    /**
     * Fournisseurs a essayer, dans l'ordre.
     *
     * @return list<AiProvider>
     */
    public function candidates(bool $vision = false): array
    {
        $usable = $this->all()->filter(fn (AiProvider $p) => $p->enabled && $p->isConfigured() && (! $vision || $p->supports_vision))->values();

        $available = $usable->filter(fn (AiProvider $p) => ! $p->isCoolingDown())->values();

        // Epinglage manuel : le fournisseur choisi passe en tete ; « strict » interdit toute bascule automatique.
        if ($this->settings->get('ai.mode') === 'forced' && ($forcedId = (int) $this->settings->get('ai.forced_provider_id'))) {
            $forced = $usable->firstWhere('id', $forcedId);
            if ($forced) {
                return $this->settings->get('ai.strict_forced')
                    ? [$forced]
                    : [$forced, ...$available->reject(fn ($p) => $p->id === $forced->id)->all()];
            }
        }

        if ($available->isNotEmpty()) {
            return $available->all();
        }

        // Tout est en pause : dernier recours, retenter les pannes passageres (surcharge, limite de debit, reseau).
        return $usable
            ->filter(fn (AiProvider $p) => in_array($p->last_error_kind, ['rate_limit', 'overloaded', 'network'], true))
            ->sortBy('disabled_until')
            ->values()
            ->all();
    }

    /** Le fournisseur qui repondra a la prochaine requete (affiche dans le tableau de bord). */
    public function active(): ?AiProvider
    {
        return $this->candidates()[0] ?? null;
    }

    public function client(AiProvider $provider): LlmClient
    {
        $config = [
            'preset' => $provider->preset,
            'api_key' => $provider->effectiveKey(),
            'base_url' => $provider->base_url,
            'model' => $provider->model,
            'vision_model' => $provider->vision_model,
            'supports_vision' => $provider->supports_vision,
            'effort' => config('platform.ai.effort'),
            'max_tokens' => config('platform.ai.max_tokens'),
            'timeout' => config('platform.ai.timeout'),
        ];

        return $provider->driver === AiProvider::DRIVER_ANTHROPIC
            ? new AnthropicLlm($config)
            : new OpenAiCompatibleLlm($config);
    }

    public function recordSuccess(AiProvider $provider, ?LlmResponse $response = null): void
    {
        $provider->forceFill([
            'status' => 'ok',
            'disabled_until' => null,
            'last_success_at' => now(),
            'requests_count' => $provider->requests_count + 1,
            'tokens_in' => $provider->tokens_in + ($response?->inputTokens ?? 0),
            'tokens_out' => $provider->tokens_out + ($response?->outputTokens ?? 0),
        ])->save();
    }

    public function recordFailure(AiProvider $provider, LlmException $e): void
    {
        $wasDown = in_array($provider->last_error_kind, ['billing', 'auth'], true) && $provider->isCoolingDown();
        $cooldown = (int) (config('platform.ai.cooldown.'.$e->kind) ?? config('platform.ai.cooldown.unknown'));

        $provider->forceFill([
            'status' => in_array($e->kind, ['billing', 'auth'], true) ? 'down' : 'ok',
            // Une requete refusee pour une raison qui nous est propre ne met pas le fournisseur en pause.
            'disabled_until' => $e->shouldFailover() ? now()->addSeconds($cooldown) : $provider->disabled_until,
            'last_error_kind' => $e->kind,
            'last_error' => mb_substr($e->getMessage(), 0, 500),
            'last_error_at' => now(),
            'failures_count' => $provider->failures_count + 1,
        ])->save();

        Log::warning('Fournisseur IA en échec', ['provider' => $provider->name, 'kind' => $e->kind, 'message' => $e->getMessage()]);

        if (in_array($e->kind, ['billing', 'auth'], true) && ! $wasDown) {
            $this->alert($provider, $e);
        }
    }

    /** Test manuel depuis le tableau de bord : une requete minimale. */
    public function test(AiProvider $provider): array
    {
        $started = microtime(true);

        try {
            $response = $this->client($provider)->complete(new LlmRequest('Réponds uniquement par le mot OK.', [['role' => 'user', 'content' => 'ping']], 16));
            $this->recordSuccess($provider, $response);

            return ['ok' => true, 'detail' => 'Réponse reçue en '.(int) ((microtime(true) - $started) * 1000).' ms (modèle '.($response->model ?? $provider->model).').'];
        } catch (LlmException $e) {
            $this->recordFailure($provider, $e);

            return ['ok' => false, 'detail' => $e->kindLabel().' : '.$e->getMessage()];
        }
    }

    /** Fournisseurs en difficulte : banniere d'alerte du tableau de bord. */
    public function problems(): Collection
    {
        return $this->all()->filter(fn (AiProvider $p) => $p->enabled && $p->isConfigured() && ($p->isCoolingDown() || $p->status === 'down'));
    }

    /** Une alerte par heure et par fournisseur, jamais plus. */
    private function alert(AiProvider $provider, LlmException $e): void
    {
        if (! Cache::add('ai.alert.'.$provider->id, 1, 3600)) {
            return;
        }

        $next = $this->all()->first(fn (AiProvider $p) => $p->id !== $provider->id && $p->isAvailable());

        // Sur les téléphones de l'équipe aussi : une panne d'IA se voit tout de suite, pas au prochain e-mail ouvert.
        try {
            app(\App\Notify\Notifier::class)->toStaff('system', 'Alerte IA : '.$provider->name.' indisponible', $e->kindLabel().($next ? ". Bascule automatique sur {$next->name}." : '. Aucun autre fournisseur disponible.'), route('admin.ai.index', [], false), ['urgent' => true, 'tag' => 'alerte-ia-'.$provider->id]);
        } catch (\Throwable $pushError) {
            report($pushError);
        }

        $to = $this->settings->alertEmail();
        if (! $to) {
            return;
        }

        try {
            Mail::to($to)->send(new Notice(
                subjectLine: 'Alerte IA : '.$provider->name.' indisponible',
                heading: "Un fournisseur d'IA ne répond plus",
                paragraphs: [
                    "Le fournisseur « {$provider->name} » ne répond plus : {$e->kindLabel()}.",
                    $e->getMessage(),
                    $next ? "Bascule automatique : les réponses passent par « {$next->name} »." : "Aucun autre fournisseur n'est disponible : les assistants répondent avec leur message de repli.",
                    'Rechargez le compte ou remplacez la clé depuis le tableau de bord (IA et fournisseurs).',
                ],
                actionLabel: 'Ouvrir IA et fournisseurs',
                actionUrl: route('admin.ai.index'),
                tone: 'danger',
                reason: 'Vous recevez cette alerte parce que vous administrez la plateforme.',
                settings: false,
            ));
        } catch (\Throwable $mailError) {
            report($mailError);
        }
    }
}
