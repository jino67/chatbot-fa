<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Message;
use App\Models\Source;
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
        return ! $workspace->is_suspended && $this->messagesThisMonth($workspace) < $workspace->limit('messages_per_month');
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
            'messages' => ['used' => $this->messagesThisMonth($workspace), 'limit' => $workspace->limit('messages_per_month')],
            'bots' => ['used' => Bot::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(), 'limit' => $workspace->limit('bots')],
            'sources' => ['used' => Source::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count(), 'limit' => $workspace->limit('sources')],
        ];
    }
}
