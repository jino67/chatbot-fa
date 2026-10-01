<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\UsageEvent;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre chaque unite de consommation avec son cout estime. Appele partout ou de l'argent part : reponse IA,
 * message WhatsApp entrant, sortant ou en modele, message vocal. Ne bloque jamais : une erreur de comptage ne doit
 * pas faire perdre une reponse a un client.
 */
class UsageMeter
{
    public function __construct(
        private readonly CostCalculator $costs,
        private readonly UsageService $usage,
    ) {}

    public function ai(int $workspaceId, ?int $botId, ?string $provider, ?string $model, int $tokensIn, int $tokensOut): ?UsageEvent
    {
        return $this->record([
            'workspace_id' => $workspaceId, 'bot_id' => $botId, 'kind' => UsageEvent::AI_ANSWER,
            'provider' => $provider, 'detail' => $model, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut,
            'cost_usd' => $this->costs->ai($provider, $model, $tokensIn, $tokensOut),
        ]);
    }

    /** @param  string  $kind  in | out | template */
    public function whatsapp(Channel $channel, string $kind, ?string $category = null, ?int $botId = null): ?UsageEvent
    {
        $provider = $channel->type === Channel::WHATSAPP_TWILIO ? 'twilio' : 'meta';

        $event = $this->record([
            'workspace_id' => $channel->workspace_id, 'bot_id' => $botId ?? $channel->bot_id, 'channel_id' => $channel->id,
            'kind' => 'wa_'.$kind, 'provider' => $provider, 'detail' => $category ? strtolower($category) : null,
            'cost_usd' => $this->costs->whatsapp($provider, $kind, $category),
        ]);

        $this->consumeCredit($channel->workspace_id);

        return $event;
    }

    /** @param  string  $kind  in | out  @param  int  $seconds  duree de l'audio */
    public function voice(int $workspaceId, ?int $botId, string $kind, int $seconds, ?string $provider = 'openai'): ?UsageEvent
    {
        return $this->record([
            'workspace_id' => $workspaceId, 'bot_id' => $botId, 'kind' => 'voice_'.$kind, 'provider' => $provider,
            'units' => max(1, $seconds), 'cost_usd' => $this->costs->voice($kind === 'in' ? 'stt' : 'tts', $seconds),
        ]);
    }

    /** Au-dela du volume inclus dans l'offre, chaque message consomme le credit achete par le client. */
    private function consumeCredit(int $workspaceId): void
    {
        try {
            $workspace = Workspace::withoutGlobalScopes()->find($workspaceId);
            if (! $workspace || $workspace->wa_credit <= 0) {
                return;
            }

            if ($this->usage->whatsappUsed($workspace) > $this->usage->whatsappAllowance($workspace)) {
                DB::table('workspaces')->where('id', $workspaceId)->where('wa_credit', '>', 0)->decrement('wa_credit');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string,mixed>  $attributes */
    private function record(array $attributes): ?UsageEvent
    {
        try {
            return UsageEvent::withoutGlobalScopes()->create($attributes + ['created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
