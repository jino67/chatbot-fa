<?php

namespace App\Leads;

use App\Channels\WhatsApp\GatewayFactory;
use App\Mail\HandoffRequested;
use App\Mail\LeadAlert;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use App\Services\UsageMeter;
use App\Services\UsageService;
use App\Support\Text;
use Illuminate\Support\Facades\Mail;

/**
 * Prévient le propriétaire d'une demande, selon les alertes qu'il a choisies (Alertes, dans son espace) :
 *  - le tableau de bord : toujours, la demande y est déjà ;
 *  - l'e-mail : une adresse au choix, sinon celle de l'assistant, sinon celle du propriétaire ;
 *  - WhatsApp : un message au numéro du propriétaire, par le canal de l'assistant. Hors des 24 h de la fenêtre de
 *    service, WhatsApp n'accepte qu'un modèle approuvé : celui de la page Alertes (sinon, l'alerte n'part pas et le
 *    journal de la demande le dit).
 * Le résultat de chaque envoi est gardé dans `alert_log` pour comprendre pourquoi une alerte n'est pas arrivée.
 */
class LeadNotifier
{
    public function __construct(
        private readonly GatewayFactory $gateways,
        private readonly UsageService $usage,
        private readonly UsageMeter $meter,
    ) {}

    public function notify(Lead $lead, bool $reminder = false): void
    {
        $workspace = Workspace::withoutGlobalScopes()->find($lead->workspace_id);
        if (! $workspace) {
            return;
        }

        $alerts = $workspace->alertSettings();
        if (! in_array($lead->kind, $alerts['kinds'], true)) {
            return; // le propriétaire a choisi de ne pas être prévenu pour ce type : la demande reste dans son tableau de bord
        }

        $result = [];
        if ($alerts['email']) {
            $result['email'] = $this->email($lead, $workspace, $alerts, $reminder);
        }
        if ($alerts['whatsapp'] && ($alerts['whatsapp_number'] || $alerts['whatsapp_members'] || $alerts['whatsapp_extra'])) {
            $result['whatsapp'] = $this->whatsapp($lead, $workspace, $alerts, $reminder);
        }

        $log = $lead->alert_log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'reminder' => $reminder] + $result;

        $lead->forceFill(['alerted_at' => now(), 'alert_log' => array_slice($log, -10)])->save();
    }

    /** @param  array<string,mixed>  $alerts */
    private function email(Lead $lead, Workspace $workspace, array $alerts, bool $reminder): string
    {
        // Une sélection explicite (adresse choisie, membres, autres adresses) remplace l'adresse par défaut.
        $explicit = $alerts['email_to'] || $alerts['email_members'] || $alerts['email_extra'];
        $to = collect([$alerts['email_to'] ?: ($explicit ? null : ($lead->bot?->handoff_email ?: $workspace->owner()?->email))])
            ->merge($workspace->users()->whereIn('id', $alerts['email_members'])->where('is_active', true)->pluck('email'))
            ->merge($alerts['email_extra'])
            ->filter()->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values()->all();

        if ($to === []) {
            return 'no_address';
        }

        try {
            $conversation = Conversation::withoutGlobalScopes()->with('bot')->find($lead->conversation_id);
            // Une demande de personne garde son e-mail historique (l'échange récent y figure) ; les autres ont le leur.
            $mail = $lead->kind === Lead::HUMAN && $conversation && ! $reminder
                ? new HandoffRequested($conversation, $lead->summary ?: 'demande du client')
                : new LeadAlert($lead, $reminder);

            Mail::to($to)->queue($mail);

            return 'sent';
        } catch (\Throwable $e) {
            report($e);

            return 'error';
        }
    }

    /** @param  array<string,mixed>  $alerts */
    private function whatsapp(Lead $lead, Workspace $workspace, array $alerts, bool $reminder): string
    {
        if (! $workspace->hasFeature('whatsapp')) {
            return 'plan';
        }

        $channel = Channel::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('status', Channel::ACTIVE)
            ->orderByRaw("case when bot_id = ? then 0 else 1 end", [$lead->bot_id])
            ->first();
        if (! $channel) {
            return 'no_channel';
        }

        if (! $this->usage->canSendWhatsApp($workspace)) {
            return 'quota';
        }

        // Numéro principal, numéros de profil des membres choisis, autres numéros : chacun est traité à part (fenêtre de 24 h propre).
        $numbers = collect([$alerts['whatsapp_number']])
            ->merge($workspace->users()->whereIn('id', $alerts['whatsapp_members'])->where('is_active', true)->pluck('phone'))
            ->merge($alerts['whatsapp_extra'])
            ->map(fn ($n) => preg_replace('/\D/', '', (string) $n))
            ->filter(fn ($n) => strlen($n) >= 8)->unique()->values()->all();

        if ($numbers === []) {
            return 'no_number';
        }

        $label = ($reminder ? 'Rappel : ' : '').$lead->label();
        $detail = Text::limit($lead->summary ?: $lead->title, 200);
        $results = [];

        try {
            $gateway = $this->gateways->for($channel);
            $template = WhatsAppTemplate::withoutGlobalScopes()
                ->where('channel_id', $channel->id)->where('name', $alerts['template'])->where('status', WhatsAppTemplate::APPROVED)->first();

            foreach ($numbers as $to) {
                if (! $this->usage->canSendWhatsApp($workspace)) {
                    $results[] = 'quota';

                    continue;
                }

                try {
                    // Le destinataire a-t-il écrit à ce numéro dans les dernières 24 h ? Alors un message libre est permis.
                    $windowOpen = Conversation::withoutGlobalScopes()
                        ->where('bot_id', $channel->bot_id)->where('channel', 'whatsapp')->where('external_id', $to)
                        ->where('last_inbound_at', '>=', now()->subHours((int) config('platform.whatsapp.session_window_hours')))
                        ->exists();

                    if ($windowOpen) {
                        $gateway->sendText($to, "{$label}\n{$detail}\nRépondez depuis votre espace : ".route('leads.index'));
                        $this->meter->whatsapp($channel, 'out', null, $channel->bot_id);
                        $results[] = 'sent';
                    } elseif ($template) {
                        $gateway->sendTemplate($to, $template->name, $template->language, [$label, $detail], $template->external_id);
                        $this->meter->whatsapp($channel, 'template', $template->category, $channel->bot_id);
                        $results[] = 'sent';
                    } else {
                        $results[] = 'template_missing';
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $results[] = 'error';
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return 'error';
        }

        // Un seul destinataire atteint suffit à parler d'envoi ; sinon, la première cause d'échec est gardée.
        return in_array('sent', $results, true) ? 'sent' : ($results[0] ?? 'error');
    }
}
