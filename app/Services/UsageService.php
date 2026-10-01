<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Message;
use App\Models\Source;
use App\Models\UsageEvent;
use App\Models\Workspace;

/** Quotas de l'offre de chaque espace (voir la table plans). */
class UsageService
{
    /** Reponses generees par l'IA ce mois-ci : l'unite facturee au client. */
    public function messagesThisMonth(Workspace $workspace): int
    {
        return Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('role', Message::ASSISTANT)
            ->where('meta->llm', true)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function canReply(Workspace $workspace): bool
    {
        return ! $workspace->is_suspended
            && ! $workspace->trialExpired()
            && $this->messagesThisMonth($workspace) < $workspace->limit('messages_per_month');
    }

    /** Messages WhatsApp du mois (recus, envoyes, en modele) : c'est le volume compte dans l'offre. */
    public function whatsappUsed(Workspace $workspace): int
    {
        return UsageEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('kind', UsageEvent::WHATSAPP)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function whatsappAllowance(Workspace $workspace): int
    {
        return $workspace->limit('whatsapp_messages_per_month');
    }

    /** Ce qu'il reste a envoyer : le volume de l'offre, puis le credit achete par le client. */
    public function whatsappRemaining(Workspace $workspace): int
    {
        return max(0, $this->whatsappAllowance($workspace) - $this->whatsappUsed($workspace)) + max(0, (int) $workspace->wa_credit);
    }

    public function canSendWhatsApp(Workspace $workspace): bool
    {
        return ! $workspace->is_suspended && ! $workspace->trialExpired() && $this->whatsappRemaining($workspace) > 0;
    }

    /** Messages vocaux du mois : ecoutes (transcrits) et envoyes (reponses audio). */
    public function voiceUsed(Workspace $workspace): int
    {
        return UsageEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('kind', [UsageEvent::VOICE_IN, UsageEvent::VOICE_OUT])
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function voiceAllowance(Workspace $workspace): int
    {
        return $workspace->hasFeature('voice') ? $workspace->limit('voice_per_month') : 0;
    }

    public function canUseVoice(Workspace $workspace): bool
    {
        return ! $workspace->is_suspended && ! $workspace->trialExpired() && $this->voiceUsed($workspace) < $this->voiceAllowance($workspace);
    }

    public function canAddBot(Workspace $workspace): bool
    {
        return Bot::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count() < $workspace->limit('bots');
    }

    public function canAddSource(Workspace $workspace): bool
    {
        return Source::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count() < $workspace->limit('sources');
    }

    public function canAddUser(Workspace $workspace): bool
    {
        return $workspace->users()->count() < $workspace->limit('members');
    }

    /** @return array{messages:array{used:int,limit:int}, bots:array{used:int,limit:int}, sources:array{used:int,limit:int}} */
    public function summary(Workspace $workspace): array
    {
        return [
            'whatsapp' => ['used' => $this->whatsappUsed($workspace), 'limit' => $this->whatsappAllowance($workspace), 'credit' => max(0, (int) $workspace->wa_credit)],
            'voice' => ['used' => $this->voiceUsed($workspace), 'limit' => $this->voiceAllowance($workspace)],
            'messages' => ['used' => $this->messagesThisMonth($workspace), 'limit' => $workspace->limit('messages_per_month')],
            'bots' => ['used' => Bot::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(), 'limit' => $workspace->limit('bots')],
            'sources' => ['used' => Source::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(), 'limit' => $workspace->limit('sources')],
        ];
    }
}
