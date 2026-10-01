<?php

namespace App\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use App\Support\Text;

/**
 * Crée les demandes à traiter. Une seule demande ouverte par conversation et par type : un client qui redit
 * « je confirme » ne déclenche pas une seconde alerte, sa demande existante est mise à jour.
 */
class LeadService
{
    public function __construct(private readonly LeadNotifier $notifier) {}

    public function capture(Conversation $conversation, string $kind, string $summary = '', ?string $title = null): Lead
    {
        $summary = Text::limit(trim(preg_replace('/\s+/u', ' ', $summary)), 400);
        $title = Text::limit($title ?: ($summary !== '' ? $summary : Lead::KINDS[$kind]), 160);

        $existing = Lead::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('kind', $kind)
            ->open()
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->forceFill(['title' => $title, 'summary' => $summary ?: $existing->summary])->save();

            return $existing;
        }

        $lead = Lead::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'bot_id' => $conversation->bot_id,
            'conversation_id' => $conversation->id,
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary !== '' ? $summary : null,
            'contact_name' => $conversation->contact_name,
            'contact_phone' => $conversation->contact_phone,
        ]);

        // Une alerte perdue ne doit jamais faire perdre la demande, ni la réponse au client.
        try {
            $this->notifier->notify($lead);
        } catch (\Throwable $e) {
            report($e);
        }

        return $lead;
    }
}
