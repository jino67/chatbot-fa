<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Qui peut recevoir un envoi groupé parmi la sélection de la page Utilisateurs (segment et filtres) : on écarte, en comptant
 * chaque raison, ceux qui ont demandé à ne plus être contactés, les comptes désactivés, ceux déjà contactés récemment, et pour
 * un e-mail les adresses masquées par Apple (elles ne reçoivent rien sans domaine déclaré chez Apple).
 */
final class BulkAudience
{
    public const SEGMENT_TEMPLATES = [
        'profil' => 'profil', 'sans_assistant' => 'demarrage', 'nouveaux' => 'demarrage', 'sans_connaissances' => 'connaissances',
        'a_tester' => 'tester', 'pas_en_ligne' => 'mise_en_ligne', 'en_ligne' => 'passer_payant', 'essai_fin' => 'essai_fin',
        'essai_expire' => 'essai_expire', 'dormants' => 'dormant', 'payants' => 'merci',
    ];

    public const EXCLUSIONS = [
        'stop' => 'ont demandé à ne plus être contactés',
        'inactif' => 'ont un compte désactivé',
        'recent' => 'ont été contactés récemment',
        'relais' => 'ont une adresse masquée par Apple',
    ];

    public function __construct(private readonly UserFilters $filters, private readonly string $channel) {}

    public static function templateFor(string $segment): string
    {
        return self::SEGMENT_TEMPLATES[$segment] ?? 'libre';
    }

    /** @return array{selected:int, eligible:Collection<int,User>, excluded:array<string,int>} */
    public function resolve(): array
    {
        $ids = (new UserQuery($this->filters))->filtered()->orderBy('users.id')->limit(5000)->pluck('users.id');
        $users = User::with('workspace')->whereIn('id', $ids)->orderBy('id')->get();
        $cooldown = now()->subDays(max(0, (int) config('people.cooldown_days')));

        $excluded = array_fill_keys(array_keys(self::EXCLUSIONS), 0);
        $eligible = $users->filter(function (User $user) use (&$excluded, $cooldown) {
            $reason = match (true) {
                $user->crm_status === 'stop' => 'stop',
                ! $user->is_active => 'inactif',
                $user->crm_last_contacted_at && $user->crm_last_contacted_at->gte($cooldown) => 'recent',
                $this->channel === 'email' && str_ends_with(mb_strtolower($user->email), '@privaterelay.appleid.com') => 'relais',
                default => null,
            };

            if ($reason) {
                $excluded[$reason]++;
            }

            return $reason === null;
        })->values();

        return ['selected' => $users->count(), 'eligible' => $eligible, 'excluded' => array_filter($excluded)];
    }
}
