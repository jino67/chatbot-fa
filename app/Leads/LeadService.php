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

    /**
     * @param  array{name?:?string, phone?:?string}  $contact  coordonnées que le client a données dans la conversation (utile sur le site web, où on ne connaît pas son numéro)
     */
    public function capture(Conversation $conversation, string $kind, string $summary = '', ?string $title = null, array $contact = []): Lead
    {
        $name = $contact['name'] ?? null;
        $phone = $contact['phone'] ?? null;

        $summary = Text::limit(trim(preg_replace('/\s+/u', ' ', $summary)), 400);
        $title = Text::limit($title ?: ($summary !== '' ? $summary : Lead::KINDS[$kind]), 160);

        $existing = Lead::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('kind', $kind)
            ->open()
            ->latest('id')
            ->first();

        if ($existing) {
            $existing->forceFill([
                'title' => $title,
                'summary' => $summary ?: $existing->summary,
                'contact_name' => $name ?: $existing->contact_name,
                'contact_phone' => $phone ?: $existing->contact_phone,
            ])->save();

            return $existing;
        }

        $lead = Lead::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'bot_id' => $conversation->bot_id,
            'conversation_id' => $conversation->id,
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary !== '' ? $summary : null,
            'contact_name' => $name ?: $conversation->contact_name,
            'contact_phone' => $phone ?: $conversation->contact_phone,
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
