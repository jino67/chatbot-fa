<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Demandes de changement d'offre faites par les clients. L'activation se fait en enregistrant le paiement. */
class PlanRequestController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', PlanRequest::REQUESTED);

        return view('admin.plan-requests', [
            'requests' => PlanRequest::withoutGlobalScopes()->with(['workspace', 'requester'])
                ->when($status, fn ($q) => $q->where('status', $status))->latest()->paginate(25)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function update(Request $request, PlanRequest $planRequest): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([PlanRequest::APPROVED, PlanRequest::REJECTED])],
            'admin_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $planRequest->update($data + ['handled_by' => $request->user()->id]);

        // Une option à la carte se donne dès que l'équipe approuve la demande (après réception du paiement).
        if ($data['status'] === PlanRequest::APPROVED && ($key = $planRequest->addonKey())) {
            $planRequest->workspace?->grantAddon($key);
        }

        return back()->with('status', 'Demande mise à jour.');
    }
}
