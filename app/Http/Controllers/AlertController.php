<?php

namespace App\Http\Controllers;

use App\Channels\WhatsApp\GatewayException;
use App\Channels\WhatsApp\TemplateManager;
use App\Models\Channel;
use App\Models\Lead;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Alertes du propriétaire : comment être prévenu d'une commande à confirmer ou d'une demande d'une personne. */
class AlertController extends Controller
{
    public const REMINDERS = [0 => 'Jamais', 15 => 'Après 15 minutes', 30 => 'Après 30 minutes', 60 => 'Après 1 heure', 120 => 'Après 2 heures'];

    public function edit(Request $request)
    {
        $workspace = $request->user()->currentWorkspace();
        $alerts = $workspace->alertSettings();
        $channel = Channel::where('status', Channel::ACTIVE)->first();
        $template = $channel ? WhatsAppTemplate::where('channel_id', $channel->id)->where('name', $alerts['template'])->first() : null;

        return view('alerts.edit', [
            'alerts' => $alerts,
            'workspace' => $workspace,
            'channel' => $channel,
            'template' => $template,
            'email' => $alerts['email_to'] ?: $workspace->owner()?->email ?: $request->user()->email,
            'members' => $workspace->users()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'phone']),
            'reminders' => self::REMINDERS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace();

        $data = $request->validate([
            'email' => ['nullable', 'boolean'],
            'email_to' => ['nullable', 'email', 'max:190'],
            'whatsapp' => ['nullable', 'boolean'],
            'whatsapp_number' => ['nullable', 'regex:/^\+?[0-9 ()\-\.]{8,20}$/'],
            'email_members' => ['nullable', 'array'],
            'email_members.*' => ['integer'],
            'whatsapp_members' => ['nullable', 'array'],
            'whatsapp_members.*' => ['integer'],
            'email_extra' => ['nullable', 'string', 'max:500'],
            'whatsapp_extra' => ['nullable', 'string', 'max:500'],
            'reminder_minutes' => ['required', Rule::in(array_keys(self::REMINDERS))],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => [Rule::in(array_keys(Lead::KINDS))],
        ], [
            'whatsapp_number.required_if' => 'Indiquez le numéro WhatsApp qui recevra les alertes.',
            'whatsapp_number.regex' => 'Le numéro doit ressembler à +226 70 00 00 00.',
        ]);

        if ($request->boolean('whatsapp') && ! $workspace->hasFeature('whatsapp')) {
            throw ValidationException::withMessages(['whatsapp' => 'Les alertes WhatsApp sont comprises dans les offres avec WhatsApp.']);
        }

        // Membres de l'équipe : seuls ceux de cet espace comptent. Autres adresses et numéros : une ligne chacun, trois au plus.
        $ids = $workspace->users()->pluck('id')->all();
        $emailMembers = array_values(array_intersect($data['email_members'] ?? [], $ids));
        $whatsappMembers = array_values(array_intersect($data['whatsapp_members'] ?? [], $ids));

        $lines = fn (?string $text) => collect(preg_split('/[\r\n,;]+/', (string) $text))->map(fn ($l) => trim($l))->filter()->unique()->values();
        $emailExtra = $lines($data['email_extra'] ?? '');
        $numberExtra = $lines($data['whatsapp_extra'] ?? '');

        if ($emailExtra->count() > 3 || $emailExtra->contains(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))) {
            throw ValidationException::withMessages(['email_extra' => 'Indiquez trois adresses e-mail valides au plus, une par ligne.']);
        }
        if ($numberExtra->count() > 3 || $numberExtra->contains(fn ($n) => ! preg_match('/^\+?[0-9 ()\-\.]{8,20}$/', $n))) {
            throw ValidationException::withMessages(['whatsapp_extra' => 'Indiquez trois numéros valides au plus, un par ligne (ex. +226 70 00 00 00).']);
        }

        $previous = $workspace->alertSettings();
        $number = filled($data['whatsapp_number'] ?? null) ? '+'.preg_replace('/\D/', '', $data['whatsapp_number']) : null;

        // WhatsApp exige au moins un destinataire : le numéro principal, un membre qui a renseigné son numéro, ou un autre numéro.
        if ($request->boolean('whatsapp')) {
            $memberPhones = $workspace->users()->whereIn('id', $whatsappMembers)->whereNotNull('phone')->count();
            if (! $number && $memberPhones === 0 && $numberExtra->isEmpty()) {
                throw ValidationException::withMessages(['whatsapp_number' => 'Indiquez au moins un numéro WhatsApp qui recevra les alertes.']);
            }
        }

        $workspace->settings = array_merge($workspace->settings ?? [], ['alerts' => [
            'email' => $request->boolean('email'),
            'email_to' => $data['email_to'] ?? null,
            'email_members' => $emailMembers,
            'email_extra' => $emailExtra->all(),
            'whatsapp' => $request->boolean('whatsapp'),
            'whatsapp_number' => $number,
            'whatsapp_members' => $whatsappMembers,
            'whatsapp_extra' => $numberExtra->map(fn ($n) => '+'.preg_replace('/\D/', '', $n))->all(),
            'template' => $previous['template'],
            'reminder_minutes' => (int) $data['reminder_minutes'],
            'kinds' => array_values($data['kinds'] ?? []),
            'chosen' => true,
        ]]);
        $workspace->save();

        return redirect()->route('alerts.edit')->with('status', 'Alertes enregistrées.');
    }

    /** Crée le modèle WhatsApp qui permet d'écrire au propriétaire en dehors des 24 h (à faire approuver par Meta). */
    public function template(Request $request, TemplateManager $templates): RedirectResponse
    {
        $channel = Channel::where('status', Channel::ACTIVE)->where('type', Channel::WHATSAPP_META)->first();
        if (! $channel) {
            return back()->with('error', 'La création du modèle passe par WhatsApp Cloud API (Meta). Avec Twilio, créez le modèle « alerte_demande » dans la console Twilio, puis synchronisez vos modèles.');
        }

        $name = $request->user()->currentWorkspace()->alertSettings()['template'];

        try {
            $templates->create($channel, [
                'name' => $name, 'language' => 'fr', 'category' => 'UTILITY',
                'body' => 'Nouvelle demande sur votre assistant : {{1}}. Détail : {{2}}. Répondez depuis votre espace.',
                'body_examples' => ['Commande à confirmer', '2 boubous brodés, 70 000 FCFA'],
                'header' => null, 'footer' => 'Alerte automatique', 'buttons' => [],
            ]);
        } catch (GatewayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Modèle envoyé à WhatsApp pour approbation (comptez jusqu\'à 24 heures). Les alertes hors fenêtre de 24 h partiront dès qu\'il sera approuvé.');
    }
}
