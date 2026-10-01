<?php

namespace App\Notify;

use App\Models\PushCampaign;
use App\Models\User;

/**
 * Envoie une campagne par morceaux. Un hébergement mutualisé limite la durée d'une requête et n'a pas de processus qui tourne
 * en permanence : on envoie donc quelques dizaines de personnes à la fois, on retient où on s'est arrêté (`cursor_user_id`) et le
 * planificateur (`notifications:dispatch`, chaque minute) reprend. Rejouable sans doublon : une personne passée n'est jamais revue.
 */
final class CampaignSender
{
    public function __construct(private readonly Notifier $notifier) {}

    /** Prépare l'envoi : compte les destinataires et passe la campagne « en cours d'envoi ». */
    public function start(PushCampaign $campaign): void
    {
        if (! in_array($campaign->status, [PushCampaign::DRAFT, PushCampaign::SCHEDULED], true)) {
            return;
        }

        $campaign->forceFill([
            'status' => PushCampaign::SENDING,
            'started_at' => now(),
            'cursor_user_id' => 0,
            'targeted' => CampaignAudience::query($campaign->audience)->count(),
        ])->save();
    }

    /**
     * Traite le prochain morceau. Renvoie vrai quand la campagne est terminée.
     *
     * @param  int  $budgetSeconds  temps maximum de ce passage
     * @param  int  $chunk  nombre maximum de personnes de ce passage
     */
    public function process(PushCampaign $campaign, int $budgetSeconds = 40, int $chunk = 60): bool
    {
        if ($campaign->status !== PushCampaign::SENDING) {
            return $campaign->status === PushCampaign::SENT;
        }

        $deadline = microtime(true) + $budgetSeconds;
        $important = $campaign->kind === PushCampaign::IMPORTANT;
        $reached = 0;
        $lastId = (int) $campaign->cursor_user_id;
        $exhausted = true;

        $users = CampaignAudience::query($campaign->audience)->where('users.id', '>', $campaign->cursor_user_id)->orderBy('users.id')->limit($chunk)->get();
        $counters = ['notified' => 0, 'pushed' => 0, 'failed' => 0, 'skipped' => 0, 'deferred' => 0, 'emailed' => 0];

        foreach ($users as $user) {
            if (microtime(true) > $deadline) {
                $exhausted = false;
                break;
            }

            $this->sendTo($campaign, $user, $important, $counters);
            $lastId = $user->id;
            $reached++;
        }

        // Moins de monde que prévu dans ce morceau : c'était le dernier.
        $finished = $exhausted && $users->count() < $chunk;

        $campaign->forceFill([
            'cursor_user_id' => $lastId,
            'notified' => $campaign->notified + $counters['notified'],
            'pushed' => $campaign->pushed + $counters['pushed'],
            'failed' => $campaign->failed + $counters['failed'],
            'skipped' => $campaign->skipped + $counters['skipped'],
            'deferred' => $campaign->deferred + $counters['deferred'],
            'emailed' => $campaign->emailed + $counters['emailed'],
            'status' => $finished ? PushCampaign::SENT : PushCampaign::SENDING,
            'finished_at' => $finished ? now() : null,
        ])->save();

        return $finished;
    }

    /** @param array<string,int> $counters */
    private function sendTo(PushCampaign $campaign, User $user, bool $important, array &$counters): void
    {
        // Un message important est une information de service : il passe même si la personne a refusé les promotions.
        $category = $important ? 'system' : 'promo';

        $notification = $this->notifier->toUser($user, $category, $campaign->title, $campaign->body, $campaign->url, [
            'campaign_id' => $campaign->id,
            'sync' => true,
            'email' => $campaign->also_email ? true : null,
            'urgent' => false,
        ]);

        if (! $notification) {
            $counters['skipped']++;

            return;
        }

        $counters['notified']++;
        $counters['pushed'] += $this->notifier->last['pushed'];
        $counters['failed'] += $this->notifier->last['failed'];
        $counters['deferred'] += $this->notifier->last['deferred'] ? 1 : 0;
        $counters['emailed'] += $this->notifier->last['emailed'] ? 1 : 0;
    }
}
