<?php

namespace App\Channels\WhatsApp;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use App\Services\UsageMeter;
use App\Services\UsageService;

/**
 * Gestion des modeles de messages WhatsApp d'un canal : synchronisation avec le fournisseur, creation,
 * suppression, et envoi a un client hors de la fenetre de 24 h.
 */
class TemplateManager
{
    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly UsageMeter $meter,
        private readonly UsageService $usage,
    ) {}

    /**
     * Recopie les modeles du fournisseur (statuts d'approbation compris).
     *
     * @return int nombre de modeles synchronises
     */
    public function sync(Channel $channel): int
    {
        $remote = $this->gateways->for($channel)->listTemplates();

        foreach ($remote as $item) {
            WhatsAppTemplate::withoutGlobalScopes()->updateOrCreate(
                ['channel_id' => $channel->id, 'name' => $item['name'], 'language' => $item['language']],
                [
                    'workspace_id' => $channel->workspace_id,
                    'external_id' => $item['external_id'],
                    'category' => $item['category'],
                    'status' => $item['status'],
                    'components' => $item['components'],
                    'body' => $item['body'],
                    'variables_count' => $item['variables_count'],
                    'rejected_reason' => $item['rejected_reason'],
                    'last_synced_at' => now(),
                ]
            );
        }

        return count($remote);
    }

    /**
     * Soumet un nouveau modele a Meta et le garde localement (statut « en attente »).
     *
     * @param  array<string,mixed>  $definition
     *
     * @throws GatewayException
     */
    public function create(Channel $channel, array $definition): WhatsAppTemplate
    {
        $gateway = $this->gateways->for($channel);

        // Un fournisseur qui ne permet pas la creation (Twilio) l'explique lui-meme dans son message d'erreur.
        $result = $gateway->createTemplate($definition);

        return WhatsAppTemplate::withoutGlobalScopes()->updateOrCreate(
            ['channel_id' => $channel->id, 'name' => $definition['name'], 'language' => $definition['language']],
            [
                'workspace_id' => $channel->workspace_id,
                'external_id' => $result['external_id'],
                'category' => $definition['category'],
                'status' => $result['status'],
                'body' => $definition['body'],
                'variables_count' => MetaCloudGateway::countVariables($definition['body']),
                'components' => $this->components($definition),
                'rejected_reason' => null,
                'last_synced_at' => now(),
            ]
        );
    }

    public function delete(WhatsAppTemplate $template): void
    {
        $channel = Channel::withoutGlobalScopes()->findOrFail($template->channel_id);

        // Un brouillon local (jamais soumis) se supprime sans appeler le fournisseur.
        if ($template->status !== WhatsAppTemplate::DRAFT) {
            $this->gateways->for($channel)->deleteTemplate($template->name, $template->external_id);
        }

        $template->delete();
    }

    /**
     * Envoie un modele approuve a l'interlocuteur d'une conversation et l'inscrit dans l'historique.
     *
     * @param  list<string>  $variables
     *
     * @throws GatewayException
     */
    public function sendTo(Conversation $conversation, WhatsAppTemplate $template, array $variables, ?string $agentName = null): Message
    {
        if (! $template->isApproved()) {
            throw new GatewayException("Ce modèle n'est pas encore approuvé par WhatsApp : il ne peut pas être envoyé.");
        }

        if (count($variables) < $template->variables_count || in_array('', array_map('trim', array_slice($variables, 0, $template->variables_count)), true)) {
            throw new GatewayException('Renseignez toutes les variables du modèle.');
        }

        $channel = Channel::withoutGlobalScopes()->findOrFail($template->channel_id);

        $workspace = Workspace::withoutGlobalScopes()->find($conversation->workspace_id);
        if ($workspace && ! $this->usage->canSendWhatsApp($workspace)) {
            throw new GatewayException('Le volume de messages WhatsApp de votre offre est atteint : rechargez des messages ou changez d\'offre.');
        }

        $providerId = $this->gateways->for($channel)->sendTemplate(
            $conversation->external_id,
            $template->name,
            $template->language,
            array_slice($variables, 0, $template->variables_count),
            $template->external_id,
        );

        $this->meter->whatsapp($channel, 'template', $template->category, $conversation->bot_id);

        $message = $conversation->messages()->create([
            'workspace_id' => $conversation->workspace_id,
            'role' => Message::AGENT,
            'content' => $template->preview($variables),
            'provider_message_id' => $providerId ?: null,
            'meta' => ['agent' => $agentName, 'template' => $template->name],
        ]);

        $conversation->forceFill(['status' => Conversation::HUMAN, 'last_message_at' => now()])->save();

        return $message;
    }

    /** @param array<string,mixed> $definition @return list<array<string,mixed>> */
    private function components(array $definition): array
    {
        $components = [];
        if (! empty($definition['header'])) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $definition['header']];
        }
        $components[] = ['type' => 'BODY', 'text' => $definition['body']];
        if (! empty($definition['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => $definition['footer']];
        }
        if (! empty($definition['buttons'])) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $definition['buttons']];
        }

        return $components;
    }
}
