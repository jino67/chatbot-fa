<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\UsageEvent;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Consommation de chaque client, calculee en temps reel a partir du journal d'usage : reponses IA, messages WhatsApp
 * (entrants, sortants, modeles), vocal, cout estime, revenu de l'offre et marge. Alimente la page « Consommation » du
 * super admin (rafraichie toutes les quelques secondes) et les alertes de solde.
 */
class ConsumptionReport
{
    public function __construct(
        private readonly CostCalculator $costs,
        private readonly UsageService $usage,
        private readonly TwilioWallet $wallet,
        private readonly PlatformSettings $settings,
    ) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        $monthStart = now()->startOfMonth();
        $dayStart = now()->startOfDay();

        $month = UsageEvent::withoutGlobalScopes()->where('created_at', '>=', $monthStart)
            ->selectRaw('workspace_id, kind, provider, count(*) as n, sum(units) as units, sum(cost_usd) as cost')
            ->groupBy('workspace_id', 'kind', 'provider')->get()->groupBy('workspace_id');

        $today = UsageEvent::withoutGlobalScopes()->where('created_at', '>=', $dayStart)
            ->selectRaw('workspace_id, count(*) as n, sum(cost_usd) as cost')->groupBy('workspace_id')->get()->keyBy('workspace_id');

        $plans = Plan::all()->keyBy('slug');
        $rows = [];

        foreach (Workspace::orderBy('name')->get() as $workspace) {
            $events = $month->get($workspace->id, collect());
            $count = fn (array $kinds) => (int) $events->whereIn('kind', $kinds)->sum('n');

            $plan = $plans->get($workspace->plan);
            $revenue = $this->monthlyRevenueUsd($plan, $workspace->currency);
            $cost = (float) $events->sum('cost');
            $allowance = $this->usage->whatsappAllowance($workspace);
            $waUsed = $count(UsageEvent::WHATSAPP);
            $aiLimit = $workspace->limit('messages_per_month');
            $aiUsed = $count([UsageEvent::AI_ANSWER]);

            $flags = [];
            if ($allowance > 0 && $waUsed >= $allowance * 0.9) {
                $flags[] = $workspace->wa_credit > 0 ? 'wa_credit' : 'wa_quota';
            }
            if ($revenue > 0 && $cost > $revenue * 0.8) {
                $flags[] = 'margin';
            }
            if ($revenue == 0.0 && $cost > 1.0) {
                $flags[] = 'free_cost';
            }

            $rows[] = [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'plan' => $plan?->name ?? $workspace->plan,
                'currency' => $workspace->currency,
                'status' => $workspace->statusLabel(),
                'ai' => ['used' => $aiUsed, 'limit' => $aiLimit],
                'wa' => ['used' => $waUsed, 'allowance' => $allowance, 'credit' => (int) $workspace->wa_credit,
                    'in' => $count([UsageEvent::WA_IN]), 'out' => $count([UsageEvent::WA_OUT]), 'template' => $count([UsageEvent::WA_TEMPLATE])],
                'voice_seconds' => (int) $events->whereIn('kind', [UsageEvent::VOICE_IN, UsageEvent::VOICE_OUT])->sum('units'),
                'cost_month' => round($cost, 4),
                'cost_today' => round((float) ($today->get($workspace->id)?->cost ?? 0), 4),
                'events_today' => (int) ($today->get($workspace->id)?->n ?? 0),
                'revenue' => round($revenue, 2),
                'margin' => round($revenue - $cost, 2),
                'margin_pct' => $revenue > 0 ? (int) round(($revenue - $cost) / $revenue * 100) : null,
                'flags' => $flags,
            ];
        }

        usort($rows, fn ($a, $b) => $b['cost_month'] <=> $a['cost_month']);

        $costMonth = array_sum(array_column($rows, 'cost_month'));
        $revenueMonth = array_sum(array_column($rows, 'revenue'));

        return [
            'generated_at' => now()->toIso8601String(),
            'totals' => [
                'cost_month' => round($costMonth, 2),
                'cost_month_xof' => round($this->costs->usdTo($costMonth, 'XOF')),
                'cost_today' => round(array_sum(array_column($rows, 'cost_today')), 2),
                'revenue_month' => round($revenueMonth, 2),
                'margin_month' => round($revenueMonth - $costMonth, 2),
                'margin_pct' => $revenueMonth > 0 ? (int) round(($revenueMonth - $costMonth) / $revenueMonth * 100) : null,
                'wa_today' => (int) UsageEvent::withoutGlobalScopes()->where('created_at', '>=', $dayStart)->whereIn('kind', UsageEvent::WHATSAPP)->count(),
                'ai_today' => (int) UsageEvent::withoutGlobalScopes()->where('created_at', '>=', $dayStart)->where('kind', UsageEvent::AI_ANSWER)->count(),
            ],
            'wallet' => $this->walletSection(),
            'providers' => $this->providerSplit($monthStart),
            'savings' => $this->savings($monthStart),
            'rows' => $rows,
            'feed' => $this->feed(),
        ];
    }

    /** Revenu mensuel de l'offre du client, converti en dollars (les offres gratuites rapportent 0). */
    private function monthlyRevenueUsd(?Plan $plan, string $currency): float
    {
        if (! $plan || $plan->isFree()) {
            return 0.0;
        }

        $code = $plan->currencyFor($currency);

        return $this->costs->toUsd(($plan->priceIn($code) ?? 0) / max(1, $plan->period_months), $code);
    }

    /** @return array<string,mixed> */
    private function walletSection(): array
    {
        $balance = $this->wallet->balance();
        $burn = (float) UsageEvent::withoutGlobalScopes()->where('provider', 'twilio')->where('created_at', '>=', now()->subDays(7))->sum('cost_usd') / 7;
        $threshold = (float) $this->settings->get('wallet.alert_below', 20);

        return $balance + [
            'burn_per_day' => round($burn, 3),
            'runway_days' => $balance['ok'] && $burn > 0 ? (int) floor($balance['balance'] / $burn) : null,
            'threshold' => $threshold,
            'low' => $balance['ok'] && $balance['balance'] < $threshold,
        ];
    }

    /** @return array<string,array{messages:int,cost:float}> */
    private function providerSplit(Carbon $since): array
    {
        $out = ['twilio' => ['messages' => 0, 'cost' => 0.0], 'meta' => ['messages' => 0, 'cost' => 0.0]];

        UsageEvent::withoutGlobalScopes()->where('created_at', '>=', $since)->whereIn('kind', UsageEvent::WHATSAPP)
            ->selectRaw('provider, count(*) as n, sum(cost_usd) as cost')->groupBy('provider')->get()
            ->each(function ($row) use (&$out) {
                if (isset($out[$row->provider])) {
                    $out[$row->provider] = ['messages' => (int) $row->n, 'cost' => round((float) $row->cost, 4)];
                }
            });

        return $out;
    }

    /** Ce que la plateforme economiserait en faisant passer les canaux Twilio par Meta direct (plus de frais de 0,005 $ par message). */
    private function savings(Carbon $since): array
    {
        $twilio = (int) UsageEvent::withoutGlobalScopes()->where('provider', 'twilio')->where('created_at', '>=', $since)->whereIn('kind', UsageEvent::WHATSAPP)->count();
        $fee = $this->costs->twilioFee();
        $daysElapsed = max(1, now()->day);

        return [
            'twilio_messages' => $twilio,
            'month_so_far' => round($twilio * $fee, 4),
            'projected_month' => round($twilio * $fee / $daysElapsed * now()->daysInMonth, 4),
        ];
    }

    /** @return list<array{at:string,workspace:string,label:string,cost:float,provider:?string}> */
    private function feed(): array
    {
        $names = Workspace::pluck('name', 'id');

        return UsageEvent::withoutGlobalScopes()->latest('id')->limit(14)->get()->map(fn (UsageEvent $e) => [
            'at' => $e->created_at?->toIso8601String(),
            'workspace' => (string) ($names[$e->workspace_id] ?? '?'),
            'label' => $e->label(),
            'cost' => round($e->cost_usd, 5),
            'provider' => $e->provider,
        ])->all();
    }
}
