<?php

namespace App\Console\Commands;

use App\Leads\LeadNotifier;
use App\Models\Lead;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Relance le propriétaire pour les demandes restées sans réponse : au plus trois rappels, espacés du délai qu'il a
 * choisi (Alertes). Une demande prise en charge, terminée ou ignorée n'est jamais relancée.
 */
class RemindLeads extends Command
{
    protected $signature = 'leads:remind';

    protected $description = 'Rappelle au propriétaire les demandes qui attendent une réponse';

    public function handle(LeadNotifier $notifier): int
    {
        $sent = 0;
        $workspaces = [];

        Lead::withoutGlobalScopes()->where('status', Lead::NEW)->where('reminders', '<', 3)->orderBy('id')->each(function (Lead $lead) use ($notifier, &$sent, &$workspaces) {
            $workspace = $workspaces[$lead->workspace_id] ??= Workspace::withoutGlobalScopes()->find($lead->workspace_id);
            $minutes = $workspace?->alertSettings()['reminder_minutes'] ?? 0;
            if ($minutes <= 0 || $workspace->is_suspended) {
                return;
            }

            $since = $lead->last_reminder_at ?? $lead->created_at;
            if ($since->copy()->addMinutes($minutes)->isFuture()) {
                return;
            }

            try {
                $notifier->notify($lead, reminder: true);
            } catch (\Throwable $e) {
                report($e);
            }

            $lead->forceFill(['reminders' => $lead->reminders + 1, 'last_reminder_at' => now()])->save();
            $sent++;
        });

        $this->info("{$sent} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}