<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\Workspace;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Enregistre un paiement recu (Mobile Money, virement, especes) et active ou prolonge l'abonnement.
 * Le paiement en ligne automatique n'est pas branche : voir docs/ARCHITECTURE.md (choix du prestataire selon le pays).
 */
class PaymentController extends Controller
{
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'slug')],
            'amount' => ['required', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'period_months' => ['required', 'integer', 'min:1', 'max:24'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = Plan::bySlug($data['plan']);

        // Renouvellement : la nouvelle periode s'ajoute a la fin de la precedente si elle n'est pas echue.
        $start = ($workspace->plan === $data['plan'] && $workspace->plan_ends_at?->isFuture()) ? $workspace->plan_ends_at : now();
        $end = $start->copy()->addMonths((int) $data['period_months']);

        Payment::create([
            'workspace_id' => $workspace->id,
            'plan' => $plan->slug,
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? $workspace->currency,
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'period_months' => $data['period_months'],
            'period_start' => $start,
            'period_end' => $end,
            'paid_at' => $data['paid_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        $workspace->update([
            'plan' => $plan->slug,
            'subscription_status' => Workspace::ACTIVE,
            'plan_started_at' => $workspace->plan === $plan->slug ? ($workspace->plan_started_at ?? now()) : now(),
            'plan_ends_at' => $end,
            'is_suspended' => false,
            'suspended_reason' => null,
        ]);

        // La demande d'offre correspondante est consideree comme traitee.
        PlanRequest::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('status', PlanRequest::REQUESTED)
            ->update(['status' => PlanRequest::APPROVED, 'handled_by' => $request->user()->id]);

        AuditLog::record('payment.recorded', $workspace->name, ['plan' => $plan->slug, 'amount' => $data['amount'], 'currency' => $data['currency'] ?? $workspace->currency, 'method' => $data['method'], 'ref' => $data['reference'] ?? null], $workspace->id);

        return back()->with('status', "Paiement enregistré : offre {$plan->name} active jusqu'au ".$end->format('d/m/Y').'.');
    }
}
