<?php

namespace App\Services\Users;

use Illuminate\Support\Carbon;

/**
 * Où en est une personne dans son parcours, et que lui proposer. Calculé uniquement à partir de ce qui existe (assistant,
 * connaissances, essais, vraies conversations, offre) : rien à saisir à la main. Un seul stade principal (le premier qui
 * manque), plus des signaux qui peuvent s'y ajouter (essai qui finit, dormant...).
 */
final class UserStage
{
    public const ACTIVE_DAYS = 14;

    public const TRIAL_ALERT_DAYS = 7;

    /** @var array<string,array{0:string,1:string,2:string}> clé => [libellé, ton, modèle de message conseillé] */
    public const STAGES = [
        'profil' => ['Profil à compléter', 'amber', 'profil'],
        'sans_assistant' => ['Sans assistant', 'amber', 'demarrage'],
        'sans_connaissances' => ['Assistant vide', 'amber', 'connaissances'],
        'a_tester' => ['À tester', 'blue', 'tester'],
        'pas_en_ligne' => ['Pas encore en ligne', 'blue', 'mise_en_ligne'],
        'en_ligne' => ['En ligne', 'green', 'passer_payant'],
        'payant' => ['Payant', 'green', 'merci'],
    ];

    /**
     * @param  object  $facts  needs_profile, bots_count, sources_count, tests_count, conversations_count, channels_count, paid, plan_ends_at, is_suspended, is_active, last_activity, created_at
     * @return array{key:string,label:string,tone:string,template:string,signals:list<array{key:string,label:string,tone:string,template:?string}>}
     */
    public static function for(object $facts, ?Carbon $now = null): array
    {
        $now ??= now();
        $live = (int) ($facts->conversations_count ?? 0) > 0 || (int) ($facts->channels_count ?? 0) > 0;

        $key = match (true) {
            (bool) ($facts->needs_profile ?? false) => 'profil',
            (bool) ($facts->paid ?? false) => 'payant',
            (int) ($facts->bots_count ?? 0) === 0 => 'sans_assistant',
            (int) ($facts->sources_count ?? 0) === 0 => 'sans_connaissances',
            $live => 'en_ligne',
            (int) ($facts->tests_count ?? 0) === 0 => 'a_tester',
            default => 'pas_en_ligne',
        };

        $signals = [];
        $ends = $facts->plan_ends_at ? Carbon::parse($facts->plan_ends_at) : null;

        if (! ($facts->paid ?? false) && $ends) {
            if ($ends->isPast()) {
                $signals[] = ['key' => 'essai_expire', 'label' => 'Essai terminé', 'tone' => 'red', 'template' => 'essai_expire'];
            } elseif ($ends->lte($now->copy()->addDays(self::TRIAL_ALERT_DAYS))) {
                $signals[] = ['key' => 'essai_fin', 'label' => 'Essai fini dans '.max(0, (int) floor($now->diffInDays($ends, false))).' j', 'tone' => 'amber', 'template' => 'essai_fin'];
            }
        }

        $last = ($facts->last_activity ?? null) ? Carbon::parse($facts->last_activity) : null;
        $created = Carbon::parse($facts->created_at);
        if ($created->lte($now->copy()->subDays(self::ACTIVE_DAYS)) && (! $last || $last->lte($now->copy()->subDays(self::ACTIVE_DAYS)))) {
            $signals[] = ['key' => 'dormant', 'label' => 'Dormant', 'tone' => 'gray', 'template' => 'dormant'];
        }

        if ($facts->is_suspended ?? false) {
            $signals[] = ['key' => 'suspendu', 'label' => 'Espace suspendu', 'tone' => 'red', 'template' => null];
        }
        if (! ($facts->is_active ?? true)) {
            $signals[] = ['key' => 'desactive', 'label' => 'Compte désactivé', 'tone' => 'red', 'template' => null];
        }

        [$label, $tone, $template] = self::STAGES[$key];

        // Le message le plus urgent d'abord : essai fini ou qui finit, puis personne qui a décroché, puis le stade.
        foreach (['essai_expire', 'essai_fin', 'dormant'] as $priority) {
            foreach ($signals as $signal) {
                if ($signal['key'] === $priority && $signal['template']) {
                    $template = $signal['template'];
                    break 2;
                }
            }
        }
        return ['key' => $key, 'label' => $label, 'tone' => $tone, 'template' => $template, 'signals' => $signals];
    }
}
