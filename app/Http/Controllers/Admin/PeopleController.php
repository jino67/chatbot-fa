<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\Notice;
use App\Models\AuditLog;
use App\Models\CustomerContact;
use App\Models\User;
use App\Notify\Notifier;
use App\Services\Analytics\ClientStats;
use App\Services\PlatformSettings;
use App\Services\UsageService;
use App\Services\Users\UserFilters;
use App\Services\Users\UserQuery;
use App\Services\Users\UserStage;
use App\Support\ContactTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les personnes inscrites, vues par le super administrateur : tout ce qu'on sait d'elles (compte, entreprise, parcours,
 * provenance), où elles en sont, et de quoi les contacter (e-mail, WhatsApp, notification, appel) en gardant la trace. Plus
 * poussé que « Espaces clients », qui gère l'entreprise : ici on suit la relation avec la personne. Voir docs/PEOPLE.md.
 */
class PeopleController extends Controller
{
    public function index(Request $request)
    {
        $filters = UserFilters::fromRequest($request);
        $query = new UserQuery($filters);

        return view('admin.people.index', [
            'filters' => $filters,
            'users' => $query->list(),
            'counts' => $query->segmentCounts(),
            'overview' => $query->overview(),
            'bySource' => $query->bySource(),
            'plans' => \App\Models\Plan::orderBy('sort')->get(['slug', 'name']),
            'staff' => User::whereIn('role', [User::SUPER_ADMIN, User::ADMIN])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, int $id, UsageService $usage)
    {
        $user = User::where('role', User::CLIENT)->with('workspace')->findOrFail($id);
        $workspace = $user->workspace;

        // Chaque ouverture d'une fiche est inscrite au journal (une fois par heure et par personne).
        if (Cache::add('person-viewed:'.$request->user()->id.':'.$user->id, 1, 3600)) {
            AuditLog::record('user.viewed', $user->email, ['utilisateur' => $user->id], $user->workspace_id);
        }

        $facts = $this->facts($user);
        $stage = UserStage::for($facts);
        $assistant = DB::table('bots')->where('workspace_id', $user->workspace_id)->orderBy('id')->value('name');
        $ends = $workspace?->plan_ends_at;

        return view('admin.people.show', [
            'person' => $user,
            'workspace' => $workspace,
            'facts' => $facts,
            'stage' => $stage,
            'suggested' => $stage['template'],
            'templates' => collect(ContactTemplates::labels())->mapWithKeys(fn ($label, $key) => [$key => ContactTemplates::render($key, $user, $request->user(), ['assistant' => $assistant, 'ends_at' => $ends])])->all(),
            'bots' => $this->bots($user),
            'socialAccounts' => $user->socialAccounts()->get(),
            'journey' => $this->journey($user, $facts),
            'origin' => $this->origin($user),
            'activity' => $workspace ? ClientStats::today(30)->workspace($workspace->id) : null,
            'usage' => $workspace ? ['messages' => $usage->messagesThisMonth($workspace), 'limit' => $workspace->limit('messages_per_month')] : null,
            'contacts' => CustomerContact::with('staff:id,name')->where('user_id', $user->id)->latest('id')->limit(40)->get(),
            'crm' => UserFilters::CRM,
            'staff' => User::whereIn('role', [User::SUPER_ADMIN, User::ADMIN])->orderBy('name')->get(['id', 'name']),
            'devices' => $user->pushSubscriptions()->count(),
            'relay' => str_ends_with(mb_strtolower($user->email), '@privaterelay.appleid.com'),
            'whatsappNumber' => $this->digits($user->phone ?: $workspace?->phone),
        ]);
    }

    /** Un contact : l'envoyer (e-mail, WhatsApp, notification) ou seulement le noter (appel, rendez-vous, note), puis le consigner. */
    public function contact(Request $request, int $id): RedirectResponse
    {
        $customer = User::where('role', User::CLIENT)->with('workspace')->findOrFail($id);
        $staff = $request->user();

        $data = $request->validate([
            'channel' => ['required', Rule::in(array_keys(CustomerContact::CHANNELS))],
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:5000'],
            'outcome' => ['nullable', Rule::in(array_keys(CustomerContact::OUTCOMES))],
            'template' => ['nullable', 'string', 'max:30'],
            'follow_up' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(array_keys(UserFilters::CRM))],
        ]);
        $channel = $data['channel'];
        $body = trim((string) ($data['body'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));

        if ($customer->crm_status === 'stop' && $channel !== 'note') {
            return back()->with('error', 'Cette personne a demandé à ne plus être contactée : seule une note peut être ajoutée.');
        }
        if (in_array($channel, ['email', 'push', 'note'], true) && $body === '') {
            return back()->withInput()->with('error', 'Écrivez un message avant d\'envoyer.');
        }
        if ($channel === 'email' && $subject === '') {
            return back()->withInput()->with('error', 'Donnez un objet à l\'e-mail.');
        }

        $redirect = null;
        $feedback = null;

        try {
            switch ($channel) {
                case 'email':
                    $this->sendEmail($customer, $staff, $subject, $body, $data['template'] ?? null);
                    $feedback = 'E-mail envoyé à '.$customer->email.'.';
                    break;

                case 'push':
                    $notification = app(Notifier::class)->toUser($customer, 'system', Str::limit($subject !== '' ? $subject : 'Un message de l\'équipe', 65, ''), Str::limit($body, 178, ''), route('dashboard', [], false), ['workspace_id' => $customer->workspace_id, 'sync' => true, 'urgent' => true]);
                    $feedback = $notification?->pushed_at
                        ? 'Notification envoyée sur ses appareils.'
                        : 'Notification déposée dans sa cloche : aucun appareil ne l\'a reçue (aucun n\'est activé, ou les notifications sont bloquées).';
                    break;

                case 'whatsapp':
                    $digits = $this->digits($customer->phone ?: $customer->workspace?->phone);
                    if (! $digits) {
                        return back()->withInput()->with('error', 'Cette personne n\'a pas donné de numéro de téléphone.');
                    }
                    $redirect = 'https://wa.me/'.$digits.($body !== '' ? '?text='.rawurlencode($body) : '');
                    $feedback = 'Conversation WhatsApp ouverte.';
                    break;
            }
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Le message n\'a pas pu partir : '.($channel === 'email' ? 'vérifiez la configuration de l\'e-mail (Paramètres).' : 'réessayez dans un instant.'));
        }

        CustomerContact::create([
            'user_id' => $customer->id, 'staff_id' => $staff->id, 'channel' => $channel,
            'subject' => $subject !== '' ? $subject : null, 'body' => $body !== '' ? $body : null,
            'outcome' => $data['outcome'] ?? null, 'template' => ContactTemplates::exists((string) ($data['template'] ?? '')) ? $data['template'] : null,
        ]);

        $this->updateFollowUp($customer, $channel, $data);
        AuditLog::record('user.contacted', $customer->email, ['canal' => $channel, 'resultat' => $data['outcome'] ?? null], $customer->workspace_id);

        // Pour WhatsApp, la page s'ouvre dans un nouvel onglet : la fiche garde l'enregistrement, WhatsApp s'ouvre à côté.
        return $redirect ? redirect()->away($redirect) : back()->with('status', $feedback ?? 'Enregistré.');
    }

    /** Le suivi : statut de la relation, prochaine relance, responsable. */
    public function crm(Request $request, int $id): RedirectResponse
    {
        $customer = User::where('role', User::CLIENT)->findOrFail($id);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(UserFilters::CRM))],
            'follow_up' => ['nullable', 'date'],
            'owner' => ['nullable', Rule::exists('users', 'id')->whereIn('role', [User::SUPER_ADMIN, User::ADMIN])],
        ]);

        $customer->forceFill([
            'crm_status' => ($data['status'] ?? null) === 'nouveau' ? null : ($data['status'] ?? null),
            'crm_next_follow_up_at' => filled($data['follow_up'] ?? null) ? Carbon::parse($data['follow_up'])->setTime(9, 0) : null,
            'crm_owner_id' => $data['owner'] ?? null,
        ])->save();

        AuditLog::record('user.crm_updated', $customer->email, ['statut' => $data['status'] ?? null], $customer->workspace_id);

        return back()->with('status', 'Suivi enregistré.');
    }

    /** La liste filtrée en CSV (séparateur « ; » et BOM pour Excel) : données personnelles, donc réservé et journalisé. */
    public function export(Request $request): StreamedResponse
    {
        $filters = UserFilters::fromRequest($request);
        $query = new UserQuery($filters);

        AuditLog::record('user.exported', 'Liste des utilisateurs', ['filtres' => $filters->toQuery()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['N°', 'Nom', 'E-mail', 'Téléphone', 'Entreprise', 'Pays', 'Inscription par', 'Offre', 'Étape', 'Suivi', 'Prochaine relance', 'Dernier contact', 'Inscrit le', 'Dernière activité', 'Assistants', 'Conversations'], ';');

            foreach ($query->each() as $r) {
                $row = [
                    $r->id, $r->name, $r->email, $r->phone, $r->company, $r->country, UserFilters::SOURCES[$r->signup_source ?: 'email'] ?? $r->signup_source, $r->plan,
                    $r->stage['label'], UserFilters::CRM[$r->crm_status ?: 'nouveau'] ?? $r->crm_status, $r->crm_next_follow_up_at ? substr($r->crm_next_follow_up_at, 0, 10) : null,
                    $r->crm_last_contacted_at ? substr($r->crm_last_contacted_at, 0, 10) : null, substr((string) $r->created_at, 0, 10), $r->last_activity ? substr((string) $r->last_activity, 0, 10) : null,
                    $r->bots_count, $r->conversations_count,
                ];
                // Une cellule qui commence par = + - @ serait lue comme une formule par le tableur : on la neutralise.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), ';');
            }
            fclose($out);
        }, 'kouma-utilisateurs-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------------------------------------------------
       Détails
       ------------------------------------------------------------------------------------------------ */

    /** Les faits qui décident du stade (les mêmes que ceux de la liste). */
    private function facts(User $user): object
    {
        $workspace = $user->workspace;
        $id = $user->workspace_id;
        $lastSeen = DB::table('analytics_sessions')->where('user_id', $user->id)->max('last_seen_at');

        $facts = (object) [
            'needs_profile' => $user->needs_profile,
            'bots_count' => DB::table('bots')->where('workspace_id', $id)->count(),
            'sources_count' => DB::table('sources')->where('workspace_id', $id)->where('status', 'ready')->count(),
            'tests_count' => DB::table('conversations')->where('workspace_id', $id)->where('channel', 'playground')->count(),
            'conversations_count' => DB::table('conversations')->where('workspace_id', $id)->where('channel', '!=', 'playground')->count(),
            'channels_count' => DB::table('channels')->where('workspace_id', $id)->where('status', 'active')->count(),
            'paid' => $workspace?->isPaid() ?? false,
            'plan_ends_at' => $workspace?->plan_ends_at,
            'is_suspended' => $workspace?->is_suspended ?? false,
            'is_active' => $user->is_active,
            'last_activity' => collect([$lastSeen, $user->last_login_at])->filter()->max(),
            'created_at' => $user->created_at,
        ];

        return $facts;
    }

    /** @return list<object> */
    private function bots(User $user): array
    {
        return DB::table('bots')->where('workspace_id', $user->workspace_id)->orderBy('id')
            ->selectRaw("bots.id, bots.name, bots.is_active, bots.public_key, bots.created_at, (select count(*) from sources s where s.bot_id = bots.id and s.status = 'ready') as sources, (select count(*) from conversations c where c.bot_id = bots.id and c.channel != 'playground') as conversations, (select max(c.last_message_at) from conversations c where c.bot_id = bots.id and c.channel != 'playground') as last_message")
            ->get()->all();
    }

    /**
     * Les grandes étapes, de la plus récente à la plus ancienne : tirées des tables de l'application, donc exactes même pour
     * les comptes d'avant la mesure.
     *
     * @return list<array{at:Carbon,label:string,tone:string}>
     */
    private function journey(User $user, object $facts): array
    {
        $id = $user->workspace_id;
        $events = [['at' => $user->created_at, 'label' => 'Inscription ('.(UserFilters::SOURCES[$user->signupSource()] ?? $user->signupSource()).')', 'tone' => 'brand']];
        $add = function (?string $at, string $label, string $tone = 'gray') use (&$events) {
            if ($at) {
                $events[] = ['at' => Carbon::parse($at), 'label' => $label, 'tone' => $tone];
            }
        };

        $add(DB::table('bots')->where('workspace_id', $id)->min('created_at'), 'Premier assistant créé', 'green');
        $add(DB::table('sources')->where('workspace_id', $id)->where('status', 'ready')->min('created_at'), 'Premières connaissances ajoutées', 'green');
        $add(DB::table('conversations')->where('workspace_id', $id)->where('channel', 'playground')->min('created_at'), 'Premier essai de l\'assistant', 'green');
        $add(DB::table('conversations')->where('workspace_id', $id)->where('channel', '!=', 'playground')->min('created_at'), 'Premier vrai client qui écrit', 'green');
        $add(DB::table('channel_requests')->where('workspace_id', $id)->min('created_at'), 'Demande d\'activation de WhatsApp', 'blue');
        $add(DB::table('channels')->where('workspace_id', $id)->where('status', 'active')->min('created_at'), 'WhatsApp activé', 'green');
        $add(DB::table('plan_requests')->where('workspace_id', $id)->min('created_at'), 'Demande de changement d\'offre', 'blue');
        $add($user->pwa_installed_at?->toDateTimeString(), 'Application installée sur un appareil', 'green');
        $add(DB::table('push_subscriptions')->where('user_id', $user->id)->min('created_at'), 'Notifications activées sur un appareil', 'green');
        foreach (DB::table('payments')->where('workspace_id', $id)->orderBy('paid_at')->get(['paid_at', 'plan', 'amount', 'currency']) as $payment) {
            $add($payment->paid_at, 'Paiement reçu : '.number_format((float) $payment->amount, 0, ',', ' ').' '.$payment->currency.' ('.$payment->plan.')', 'green');
        }
        $add($user->last_login_at?->toDateTimeString(), 'Dernière connexion', 'gray');

        usort($events, fn ($a, $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return $events;
    }

    /** Par où la personne est arrivée : la première visite de ce navigateur (avant l'inscription comprise), si la mesure l'a vue. @return array<string,mixed>|null */
    private function origin(User $user): ?array
    {
        $visitor = DB::table('analytics_sessions')->where('user_id', $user->id)->value('visitor_key');
        $first = $visitor ? DB::table('analytics_sessions')->where('visitor_key', $visitor)->orderBy('started_at')->first() : null;

        if (! $first) {
            return null;
        }

        return [
            'at' => Carbon::parse($first->started_at),
            'source' => $first->source, 'referrer' => $first->referrer_host, 'campaign' => $first->utm_campaign, 'medium' => $first->utm_medium, 'utm_source' => $first->utm_source,
            'page' => $first->entry_path, 'device' => $first->device, 'browser' => $first->browser, 'os' => $first->os, 'country' => $first->country, 'language' => $first->language,
            'visits' => DB::table('analytics_sessions')->where('visitor_key', $visitor)->count(),
        ];
    }

    /** Le numéro en chiffres seuls pour un lien wa.me (l'indicatif doit déjà y être), ou null. */
    private function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        $digits = preg_replace('/^00/', '', (string) $digits);

        return strlen((string) $digits) >= 8 ? $digits : null;
    }

    private function sendEmail(User $customer, User $staff, string $subject, string $body, ?string $template): void
    {
        $brand = app(PlatformSettings::class)->brand();
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: [])));
        $action = ContactTemplates::exists((string) $template) ? ContactTemplates::render((string) $template, $customer, $staff) : null;

        $notice = new Notice(
            subjectLine: $subject,
            heading: $subject,
            paragraphs: $paragraphs,
            actionLabel: $action['action_label'] ?? null,
            actionUrl: $action['action_url'] ?? null,
            greetingName: $customer->name,
            reason: 'Message de l\'équipe '.$brand['name'].' : répondez simplement à cet e-mail pour nous écrire.',
            settings: false,
        );
        $notice->replyTo($brand['email'] ?: $staff->email, $brand['name']);

        Mail::to($customer->email)->send($notice);
    }

    /** Après un contact : date du dernier contact, relance prévue ou faite, statut de la relation. @param array<string,mixed> $data */
    private function updateFollowUp(User $customer, string $channel, array $data): void
    {
        $changes = [];

        if ($channel !== 'note') {
            $changes['crm_last_contacted_at'] = now();
        }

        if (filled($data['follow_up'] ?? null)) {
            $changes['crm_next_follow_up_at'] = Carbon::parse($data['follow_up'])->setTime(9, 0);
        } elseif ($channel !== 'note' && $customer->crm_next_follow_up_at && $customer->crm_next_follow_up_at->isPast()) {
            $changes['crm_next_follow_up_at'] = null; // la relance prévue vient d'être faite
        }

        $status = $data['status'] ?? match ($data['outcome'] ?? null) {
            'interested' => 'interesse',
            'converted' => 'client',
            'not_interested' => 'perdu',
            'replied' => in_array($customer->crm_status, [null, 'contacte'], true) ? 'en_discussion' : null,
            default => $channel !== 'note' && $customer->crm_status === null ? 'contacte' : null,
        };
        if ($status) {
            $changes['crm_status'] = $status === 'nouveau' ? null : $status;
        }

        if ($changes) {
            $customer->forceFill($changes)->save();
        }
    }
}
