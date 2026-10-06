<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PlanRequest;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Remet à zéro ce que les essais ont laissé dans les comptes : paiements simulés, crédit de messages WhatsApp ajouté à la
 * main, offre payante donnée pour tester, consommation mesurée, demandes d'offre. Rien n'est effacé sans que le super
 * administrateur ait désigné les espaces un par un ; une copie de ce qui disparaît est écrite avant l'effacement.
 */
class TestDataReset
{
    /** @var array<string,array{0:string,1:string}> option => [libellé, explication] */
    public const OPTIONS = [
        'payments' => ['Effacer les paiements enregistrés', "Les paiements d'offre et de recharge disparaissent de l'espace et des chiffres d'affaires de la vue d'ensemble."],
        'credit' => ['Remettre le crédit de messages WhatsApp à 0', "Le crédit ajouté par les recharges (hors volume inclus dans l'offre) repart de zéro."],
        'plan' => ["Remettre l'espace sur l'offre Découverte", "Offre gratuite, sans date d'échéance, espace réactivé s'il était suspendu."],
        'usage' => ['Effacer la consommation mesurée', 'Messages WhatsApp comptés, minutes de voix et coûts estimés de la page Consommation.'],
        'requests' => ["Effacer les demandes d'offre", "Les demandes d'offre en attente ou déjà traitées."],
    ];

    /**
     * Ce que chaque espace contient d'« argent » ou de compteurs, pour que le super administrateur choisisse en connaissance de cause.
     *
     * @return list<array{workspace:Workspace, payments:int, paid:array<string,float>, last_payment:?Carbon, credit:int, usage:int, requests:int}>
     */
    public function overview(): array
    {
        $rows = [];

        foreach (Workspace::withoutGlobalScopes()->orderBy('name')->get() as $workspace) {
            $payments = Payment::withoutGlobalScopes()->where('workspace_id', $workspace->id);
            $count = (clone $payments)->count();
            $usage = UsageEvent::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();
            $requests = PlanRequest::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count();

            if ($count + $usage + $requests === 0 && (int) $workspace->wa_credit === 0 && $workspace->plan === 'free') {
                continue; // rien à remettre à zéro
            }

            $last = (clone $payments)->max('paid_at');

            $rows[] = [
                'workspace' => $workspace,
                'payments' => $count,
                'paid' => (clone $payments)->selectRaw('currency, sum(amount) as total')->groupBy('currency')->pluck('total', 'currency')->map(fn ($t) => (float) $t)->all(),
                'last_payment' => $last ? Carbon::parse($last) : null,
                'credit' => (int) $workspace->wa_credit,
                'usage' => $usage,
                'requests' => $requests,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $workspaceIds
     * @param  list<string>  $options  clés de self::OPTIONS
     * @return array{counts:array<string,int>, backup:string, workspaces:int}
     */
    public function reset(array $workspaceIds, array $options, ?User $by = null): array
    {
        $options = array_values(array_intersect($options, array_keys(self::OPTIONS)));
        $workspaces = Workspace::withoutGlobalScopes()->whereIn('id', $workspaceIds)->get();
        $ids = $workspaces->pluck('id')->all();

        $counts = ['payments' => 0, 'credit' => 0, 'plan' => 0, 'usage' => 0, 'requests' => 0];

        if ($ids === [] || $options === []) {
            return ['counts' => $counts, 'backup' => '', 'workspaces' => 0];
        }

        $backup = [
            'at' => now()->toIso8601String(),
            'by' => $by?->email,
            'options' => $options,
            'workspaces' => $workspaces->map(fn ($w) => $w->only(['id', 'name', 'plan', 'subscription_status', 'plan_started_at', 'plan_ends_at', 'is_suspended', 'wa_credit']))->all(),
        ];

        DB::transaction(function () use ($ids, $options, &$counts, &$backup) {
            if (in_array('payments', $options, true)) {
                $payments = Payment::withoutGlobalScopes()->whereIn('workspace_id', $ids)->get();
                $backup['payments'] = $payments->toArray();
                $counts['payments'] = $payments->count();
                Payment::withoutGlobalScopes()->whereIn('workspace_id', $ids)->delete();
            }
            if (in_array('requests', $options, true)) {
                $requests = PlanRequest::withoutGlobalScopes()->whereIn('workspace_id', $ids)->get();
                $backup['plan_requests'] = $requests->toArray();
                $counts['requests'] = $requests->count();
                PlanRequest::withoutGlobalScopes()->whereIn('workspace_id', $ids)->delete();
            }
            if (in_array('usage', $options, true)) {
                $usage = UsageEvent::withoutGlobalScopes()->whereIn('workspace_id', $ids);
                $backup['usage_events'] = ['count' => (clone $usage)->count(), 'cost_usd' => round((float) (clone $usage)->sum('cost_usd'), 4)];
                $counts['usage'] = $backup['usage_events']['count'];
                $usage->delete();
            }
            if (in_array('credit', $options, true)) {
                $counts['credit'] = Workspace::withoutGlobalScopes()->whereIn('id', $ids)->where('wa_credit', '>', 0)->count();
                Workspace::withoutGlobalScopes()->whereIn('id', $ids)->update(['wa_credit' => 0]);
            }
            if (in_array('plan', $options, true)) {
                $counts['plan'] = Workspace::withoutGlobalScopes()->whereIn('id', $ids)->where('plan', '!=', 'free')->count();
                Workspace::withoutGlobalScopes()->whereIn('id', $ids)->update([
                    'plan' => 'free', 'subscription_status' => Workspace::ACTIVE, 'plan_started_at' => null, 'plan_ends_at' => null,
                    'is_suspended' => false, 'suspended_reason' => null,
                ]);
            }
        });

        $name = 'backups/remise-a-zero-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($name, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        AuditLog::record('payment.test_reset', count($ids).' espace(s)', ['options' => $options, 'counts' => $counts, 'backup' => $name]);

        return ['counts' => $counts, 'backup' => $name, 'workspaces' => count($ids)];
    }
}
