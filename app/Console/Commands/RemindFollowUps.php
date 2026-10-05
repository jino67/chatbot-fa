<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notify\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Chaque matin, prévient l'équipe des relances à faire aujourd'hui (page Utilisateurs, segment « À relancer ») : chaque
 * responsable reçoit les siennes, les personnes sans responsable vont à tous les super administrateurs. Une notification par
 * personne et par jour, dans la cloche et sur les téléphones activés.
 */
class RemindFollowUps extends Command
{
    protected $signature = 'people:remind';

    protected $description = 'Prévient l\'équipe des relances à faire aujourd\'hui';

    public function handle(Notifier $notifier): int
    {
        $due = User::where('role', User::CLIENT)->where('is_active', true)
            ->whereNotNull('crm_next_follow_up_at')->where('crm_next_follow_up_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('crm_status')->orWhereNotIn('crm_status', ['stop', 'perdu', 'client']))
            ->orderBy('crm_next_follow_up_at')->get(['id', 'name', 'crm_owner_id']);

        if ($due->isEmpty()) {
            $this->info('Aucune relance à faire.');

            return self::SUCCESS;
        }

        $team = User::whereIn('role', [User::SUPER_ADMIN, User::ADMIN])->where('is_active', true)->get()->keyBy('id');
        $supers = $team->where('role', User::SUPER_ADMIN);
        $byRecipient = [];

        foreach ($due as $person) {
            $owner = $person->crm_owner_id ? $team->get($person->crm_owner_id) : null;
            foreach ($owner ? [$owner] : $supers as $recipient) {
                $byRecipient[$recipient->id][] = $person->name;
            }
        }

        $sent = 0;
        foreach ($byRecipient as $id => $names) {
            $count = count($names);
            $list = collect($names)->take(3)->implode(', ').($count > 3 ? ' et '.($count - 3).' autre'.($count > 4 ? 's' : '') : '');

            $notifier->toUser(
                $team[$id], 'system', $count.' relance'.($count > 1 ? 's' : '').' à faire', $list,
                route('admin.people.index', ['segment' => 'a_relancer'], false),
                ['tag' => 'relances-'.now()->format('Y-m-d'), 'dedupe_minutes' => 1200],
            );
            $sent++;
        }

        $this->info($due->count().' relance(s) à faire, '.$sent.' membre(s) de l\'équipe prévenu(s).');

        return self::SUCCESS;
    }
}
