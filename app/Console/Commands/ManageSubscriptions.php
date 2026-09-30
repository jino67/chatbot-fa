<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Cycle de vie des abonnements, a executer chaque jour :
 *  - rappel quelques jours avant l'echeance ;
 *  - « paiement en retard » a l'echeance, avec une periode de grace ;
 *  - retour a l'offre par defaut (gratuite) une fois la grace ecoulee : les donnees sont conservees.
 */
class ManageSubscriptions extends Command
{
    protected $signature = 'platform:subscriptions';

    protected $description = 'Rappels d\'échéance, période de grâce et retour à l\'offre gratuite des abonnements échus';

    public function handle(): int
    {
        $default = Plan::default();
        $grace = (int) config('platform.billing.grace_days');
        $reminder = (int) config('platform.billing.reminder_days');
        $counts = ['reminders' => 0, 'late' => 0, 'downgraded' => 0];

        Workspace::whereNotNull('plan_ends_at')->where('plan', '!=', $default?->slug)->each(function (Workspace $workspace) use ($default, $grace, $reminder, &$counts) {
            $days = $workspace->daysLeft();

            if ($days !== null && $days < -$grace) {
                $from = $workspace->plan;
                $workspace->update(['plan' => $default->slug, 'plan_ends_at' => null, 'subscription_status' => Workspace::CANCELED]);
                AuditLog::record('subscription.expired', $workspace->name, ['from' => $from], $workspace->id);
                $this->notify($workspace, 'Votre abonnement est arrivé à échéance', "Votre espace est revenu à l'offre {$default->name}. Vos assistants et vos données sont conservés ; pour retrouver votre offre, renouvelez depuis la page Abonnement.");
                $counts['downgraded']++;
            } elseif ($days !== null && $days < 0 && $workspace->subscription_status !== Workspace::PAST_DUE) {
                $workspace->update(['subscription_status' => Workspace::PAST_DUE]);
                $this->notify($workspace, 'Votre abonnement est arrivé à échéance', "Vous avez {$grace} jours pour le renouveler sans rien perdre. Renouvelez depuis la page Abonnement.");
                $counts['late']++;
            } elseif ($days !== null && $days >= 0 && $days <= $reminder && ! ($workspace->settings['reminded_'.$workspace->plan_ends_at->format('Ymd')] ?? false)) {
                $settings = $workspace->settings ?? [];
                $settings['reminded_'.$workspace->plan_ends_at->format('Ymd')] = true;
                $workspace->update(['settings' => $settings]);
                $this->notify($workspace, "Votre abonnement se termine dans {$days} jour(s)", 'Pensez à le renouveler depuis la page Abonnement pour ne pas interrompre vos assistants.');
                $counts['reminders']++;
            }
        });

        $this->info("Rappels : {$counts['reminders']}, en retard : {$counts['late']}, repassés à l'offre gratuite : {$counts['downgraded']}.");

        return self::SUCCESS;
    }

    private function notify(Workspace $workspace, string $subject, string $body): void
    {
        $to = $workspace->owner()?->email;
        if (! $to) {
            return;
        }

        try {
            Mail::raw("Bonjour,\n\n{$body}\n\nEspace : {$workspace->name}", fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
