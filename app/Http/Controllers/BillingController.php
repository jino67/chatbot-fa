<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Services\PlatformSettings;
use App\Services\UsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/** Abonnement du client : offre actuelle, consommation, historique de paiements, demande de changement d'offre. */
class BillingController extends Controller
{
    public function show(Request $request, UsageService $usage, PlatformSettings $settings)
    {
        $workspace = $request->user()->currentWorkspace();

        return view('billing.show', [
            'workspace' => $workspace,
            'plan' => $workspace->planModel(),
            'usage' => $usage->summary($workspace),
            'plans' => Plan::where('audience', $workspace->planModel()?->audience ?: 'business')->where('is_public', true)->orderBy('sort')->get(),
            'payments' => Payment::where('workspace_id', $workspace->id)->latest('paid_at')->limit(12)->get(),
            'pending' => PlanRequest::where('workspace_id', $workspace->id)->where('status', PlanRequest::REQUESTED)->latest()->first(),
            'instructions' => $settings->get('billing.instructions'),
            'brand' => $settings->brand(),
        ]);
    }

    /** Le client demande une offre ; l'equipe l'active apres reception du paiement. */
    public function request(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();

        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'slug')->where('is_public', true)],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['plan'] === $workspace->plan) {
            return back()->with('error', 'Vous êtes déjà sur cette offre.');
        }

        // Une option a la carte (« addon:... ») a sa propre demande : elle n'est jamais ecrasee par un changement d'offre.
        $open = PlanRequest::where('workspace_id', $workspace->id)->where('status', PlanRequest::REQUESTED)->where('plan', 'not like', PlanRequest::ADDON_PREFIX.'%')->first();
        if ($open) {
            $open->update(['plan' => $data['plan'], 'message' => $data['message'] ?? $open->message]);
        } else {
            PlanRequest::create([
                'workspace_id' => $workspace->id,
                'requester_id' => $request->user()->id,
                'plan' => $data['plan'],
                'message' => $data['message'] ?? null,
            ]);
        }

        AuditLog::record('billing.plan_requested', $data['plan'], [], $workspace->id);

        if ($to = config('platform.admin_email') ?: $settings->get('brand.email')) {
            try {
                Mail::raw(
                    "Demande de changement d'offre\n\nEspace : {$workspace->name}\nOffre demandée : {$data['plan']}\nDemandeur : {$request->user()->name} ({$request->user()->email})\n\n".route('admin.plan-requests.index'),
                    fn ($m) => $m->to($to)->subject("Demande d'offre : {$workspace->name}")
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('status', 'Demande enregistrée. Payez selon les indications ci-dessous et envoyez-nous la référence : votre offre est activée dès réception.');
    }
}
