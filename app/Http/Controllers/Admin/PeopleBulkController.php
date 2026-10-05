<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Users\BulkAudience;
use App\Services\Users\ContactSender;
use App\Services\Users\UserFilters;
use App\Support\ContactTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Écrire à toute une sélection de la page Utilisateurs (un segment, des filtres), par lots. Le message est personnalisé pour
 * chacun ({prenom}, {entreprise}, {assistant}...). Garde-fous : seulement e-mail et notification (jamais de WhatsApp en masse),
 * « Ne plus contacter » et les contacts récents sont écartés, un lot par clic, et un plafond d'e-mails par jour pour ne pas
 * abîmer la réputation du domaine d'envoi. Chaque envoi est consigné comme un contact individuel.
 */
class PeopleBulkController extends Controller
{
    public function show(Request $request, ContactSender $sender)
    {
        [$filters, $channel, $template] = $this->choices($request);
        $audience = (new BulkAudience($filters, $channel))->resolve();
        $first = $audience['eligible']->first();
        $raw = ContactTemplates::raw($template);

        // Le texte modifiable garde ses variables ; l'aperçu montre le message tel que le recevra le premier destinataire.
        $subject = $channel === 'push' ? Str::limit($raw['subject'], 65, '') : $raw['subject'];
        $body = $channel === 'push' ? Str::limit(explode("\n\n", $raw['email'])[0], 170, '') : $raw['email'];
        $vars = $first ? ContactTemplates::variables($first, $request->user(), $sender->context($first)) : [];

        return view('admin.people.bulk', [
            'filters' => $filters,
            'channel' => $channel,
            'template' => $template,
            'templates' => ContactTemplates::labels(),
            'audience' => $audience,
            'exclusions' => BulkAudience::EXCLUSIONS,
            'subject' => old('subject', $subject),
            'body' => old('body', $body),
            'preview' => $first ? ['who' => $first->name, 'subject' => strtr($subject, $vars), 'body' => ContactTemplates::fill($body, $vars)] : null,
            'batch' => max(1, (int) config('people.batch')),
            'cooldown' => (int) config('people.cooldown_days'),
            'cap' => $sender->dailyEmailCap(),
            'sentToday' => $sender->emailsSentToday(),
            'segmentLabel' => UserFilters::SEGMENTS[$filters->segment][0],
        ]);
    }

    public function send(Request $request, ContactSender $sender): RedirectResponse
    {
        [$filters, $channel, $template] = $this->choices($request);
        $staff = $request->user();

        $data = $request->validate([
            'subject' => [$channel === 'email' ? 'required' : 'nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:5000'],
            'reviewed' => ['accepted'],
        ], ['reviewed.accepted' => 'Cochez la case pour confirmer que vous avez relu le message.']);

        $audience = (new BulkAudience($filters, $channel))->resolve();
        $limit = max(1, (int) config('people.batch'));

        if ($channel === 'email') {
            $left = $sender->dailyEmailCap() - $sender->emailsSentToday();
            if ($left <= 0) {
                return back()->withInput()->with('error', 'Le plafond de '.$sender->dailyEmailCap().' e-mails par jour est atteint. Reprenez demain : monter vite le volume d\'un domaine récent le fait classer en indésirables.');
            }
            $limit = min($limit, $left);
        }

        $recipients = $audience['eligible']->take($limit);
        if ($recipients->isEmpty()) {
            return back()->with('error', 'Personne à qui écrire dans cette sélection : tous sont écartés ou déjà contactés récemment.');
        }

        $sent = 0;
        $failed = 0;

        foreach ($recipients as $person) {
            $vars = ContactTemplates::variables($person, $staff, $sender->context($person));
            $subject = strtr((string) ($data['subject'] ?? ''), $vars);
            $body = ContactTemplates::fill($data['body'], $vars);

            try {
                $channel === 'email' ? $sender->email($person, $staff, $subject, $body, $template) : $sender->push($person, $subject, $body);
            } catch (\Throwable $e) {
                report($e);
                $failed++;
                // Un serveur de messagerie en panne échouerait pour chacun : on s'arrête après trois échecs de suite.
                if ($failed >= 3 && $sent === 0) {
                    break;
                }

                continue;
            }

            $sender->record($person, $staff, $channel, $subject, $body, null, $template);
            $sender->afterContact($person, $channel);
            $sent++;
        }

        AuditLog::record('user.bulk_contacted', 'Envoi groupé : '.$filters->segment, ['canal' => $channel, 'envoyes' => $sent, 'echecs' => $failed, 'modele' => $template, 'segment' => $filters->segment]);

        $left = max(0, $audience['eligible']->count() - $sent);
        $message = $sent.' personne(s) jointe(s)'.($failed ? ', '.$failed.' échec(s)' : '').'.'.($left > 0 && $sent > 0 ? ' Il en reste '.$left.' : cliquez à nouveau pour continuer.' : '');

        return redirect()->route('admin.people.bulk', $filters->toQuery() + ['canal' => $channel, 'modele' => $template])
            ->with($sent > 0 ? 'status' : 'error', $sent > 0 ? $message : 'Aucun message n\'a pu partir : vérifiez la configuration de l\'e-mail (Paramètres).');
    }

    /** @return array{0:UserFilters,1:string,2:string} la sélection, le moyen et le modèle choisis */
    private function choices(Request $request): array
    {
        // La sélection voyage dans l'adresse (GET) ou dans des champs cachés (POST) : mêmes noms.
        $filters = UserFilters::fromRequest(Request::create('/', 'GET', $request->all()));
        $channel = in_array($request->input('canal'), ['email', 'push'], true) ? $request->input('canal') : 'email';
        $template = ContactTemplates::exists((string) $request->input('modele')) ? (string) $request->input('modele') : BulkAudience::templateFor($filters->segment);

        return [$filters, $channel, $template];
    }
}
