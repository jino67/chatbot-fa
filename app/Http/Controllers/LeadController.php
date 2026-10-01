<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Demandes à traiter : commandes à confirmer, rendez-vous, devis, clients qui demandent une personne. */
class LeadController extends Controller
{
    public function index(Request $request)
    {
        $tab = in_array($request->query('etat'), ['open', 'done', 'all'], true) ? $request->query('etat') : 'open';

        $query = Lead::with(['bot', 'assignee'])->latest('id');
        if ($tab === 'open') {
            $query->open();
        } elseif ($tab === 'done') {
            $query->whereIn('status', [Lead::DONE, Lead::DISMISSED]);
        }

        return view('leads.index', [
            'leads' => $query->paginate(30)->withQueryString(),
            'tab' => $tab,
            'counts' => [
                'open' => Lead::open()->count(),
                'new' => Lead::where('status', Lead::NEW)->count(),
                'done' => Lead::whereIn('status', [Lead::DONE, Lead::DISMISSED])->count(),
            ],
            'alertsChosen' => $request->user()->currentWorkspace()->alertSettings()['chosen'],
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['take', 'done', 'dismiss', 'reopen'])]])['action'];
        $user = $request->user();

        match ($action) {
            'take' => $lead->forceFill(['status' => Lead::TAKEN, 'assigned_to' => $user->id, 'taken_at' => now()]),
            'done' => $lead->forceFill(['status' => Lead::DONE, 'assigned_to' => $lead->assigned_to ?? $user->id, 'closed_at' => now()]),
            'dismiss' => $lead->forceFill(['status' => Lead::DISMISSED, 'closed_at' => now()]),
            'reopen' => $lead->forceFill(['status' => Lead::NEW, 'closed_at' => null, 'reminders' => 0, 'last_reminder_at' => null]),
        };
        $lead->save();

        return back()->with('status', match ($action) {
            'take' => 'Demande prise en charge : les rappels s\'arrêtent.',
            'done' => 'Demande terminée.',
            'dismiss' => 'Demande ignorée.',
            'reopen' => 'Demande rouverte.',
        });
    }
}
