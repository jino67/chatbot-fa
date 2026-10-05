<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Lead;
use App\Models\WhatsAppTemplate;
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
            'confirmable' => $this->confirmable($request),
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

    /**
     * Les assistants qui ont déjà, approuvé par WhatsApp, le modèle de confirmation d'un type de demande : le bouton
     * « Confirmer » ne s'affiche que pour eux (sinon il mènerait à une page sans modèle). Clés « botId:nomDuModèle ».
     *
     * @return array<string, true>
     */
    private function confirmable(Request $request): array
    {
        if (! $request->user()->currentWorkspace()->hasFeature('templates')) {
            return [];
        }

        $names = array_column(Lead::CONFIRMATIONS, 0);
        $templates = WhatsAppTemplate::where('status', WhatsAppTemplate::APPROVED)->whereIn('name', $names)->get(['channel_id', 'name']);
        $bots = Channel::whereIn('id', $templates->pluck('channel_id')->unique())
            ->whereIn('type', [Channel::WHATSAPP_META, Channel::WHATSAPP_TWILIO])->where('status', Channel::ACTIVE)
            ->pluck('bot_id', 'id');

        $keys = [];
        foreach ($templates as $template) {
            if ($bot = $bots[$template->channel_id] ?? null) {
                $keys[$bot.':'.$template->name] = true;
            }
        }

        return $keys;
    }
}
