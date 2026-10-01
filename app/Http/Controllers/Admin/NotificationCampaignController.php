<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PushCampaign;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Notify\CampaignAudience;
use App\Notify\CampaignSender;
use App\Notify\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Les envois de l'équipe aux clients : promotions, nouveautés, messages importants. On prépare (aperçu en direct, nombre de
 * personnes touchées), on s'envoie un essai, puis on envoie tout de suite ou on programme. Les résultats se lisent ensuite.
 * Ouvert à l'équipe (administrateurs et super administrateurs) ; chaque envoi est consigné dans le journal d'audit.
 */
class NotificationCampaignController extends Controller
{
    public function index()
    {
        $clientUsers = User::where('role', User::CLIENT)->where('is_active', true)->count();
        $withPush = (int) PushSubscription::whereIn('user_id', User::where('role', User::CLIENT)->where('is_active', true)->select('id'))->distinct()->count('user_id');

        return view('admin.notifications.index', [
            'campaigns' => PushCampaign::with('author')->latest('id')->limit(40)->get(),
            'reach' => ['users' => $clientUsers, 'with_push' => $withPush, 'devices' => PushSubscription::count()],
        ]);
    }

    public function create(Request $request)
    {
        // « Dupliquer » préremplit le formulaire avec une campagne passée.
        $source = $request->integer('copie') ? PushCampaign::find($request->integer('copie')) : null;

        return view('admin.notifications.form', $this->formData($source ? new PushCampaign($source->only(['title', 'body', 'url', 'kind', 'audience', 'also_email'])) : new PushCampaign(['audience' => ['type' => 'all']])));
    }

    public function edit(PushCampaign $campaign)
    {
        abort_unless($campaign->isEditable(), 403, 'Cette campagne est déjà partie : elle ne se modifie plus.');

        return view('admin.notifications.form', $this->formData($campaign));
    }

    public function store(Request $request, CampaignSender $sender, Notifier $notifier): RedirectResponse
    {
        $campaign = new PushCampaign;

        return $this->save($request, $campaign, $sender, $notifier);
    }

    public function update(Request $request, PushCampaign $campaign, CampaignSender $sender, Notifier $notifier): RedirectResponse
    {
        abort_unless($campaign->isEditable(), 403, 'Cette campagne est déjà partie : elle ne se modifie plus.');

        return $this->save($request, $campaign, $sender, $notifier);
    }

    public function show(PushCampaign $campaign)
    {
        return view('admin.notifications.show', [
            'campaign' => $campaign->load('author'),
            'audienceText' => CampaignAudience::describe($campaign->audience),
            'readCount' => $campaign->readCount(),
        ]);
    }

    /** Une campagne programmée peut être annulée tant qu'elle n'est pas partie. */
    public function cancel(PushCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isEditable(), 403);

        $campaign->update(['status' => PushCampaign::CANCELED]);
        AuditLog::record('notification.canceled', $campaign->title);

        return redirect()->route('admin.notifications.index')->with('status', 'Campagne annulée.');
    }

    /** Combien de personnes et d'appareils cette audience touche, pendant qu'on la règle. */
    public function estimate(Request $request): JsonResponse
    {
        return response()->json(CampaignAudience::estimate($this->audienceFrom($request)));
    }

    /** M'envoyer un essai : le message arrive sur mes appareils, sans être compté dans une campagne. */
    public function test(Request $request, Notifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:65'],
            'body' => ['required', 'string', 'max:178'],
            'url' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        if (! $user->pushSubscriptions()->exists()) {
            return response()->json(['sent' => false, 'message' => 'Aucun de vos appareils n\'est activé : activez d\'abord les notifications dans « Mes notifications », puis réessayez.'], 409);
        }

        $notification = $notifier->toUser($user, 'system', '[Essai] '.$data['title'], $data['body'], $data['url'] ?? null, ['sync' => true, 'mark_read' => true, 'ignore_cap' => true, 'urgent' => true, 'tag' => 'campagne-essai']);

        return response()->json([
            'sent' => $notification?->pushed_at !== null,
            'message' => $notification?->pushed_at !== null ? 'Essai envoyé : il doit apparaître dans quelques secondes sur vos appareils.' : 'L\'essai n\'est pas parti. Vérifiez que les notifications ne sont pas bloquées sur votre appareil.',
        ]);
    }

    /* ------------------------------------------------------------------------------------------------ */

    private function save(Request $request, PushCampaign $campaign, CampaignSender $sender, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:65'],
            'body' => ['required', 'string', 'max:178'],
            'url' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::in([PushCampaign::PROMO, PushCampaign::IMPORTANT])],
            'audience_type' => ['required', Rule::in(array_keys(CampaignAudience::TYPES))],
            'plans' => ['nullable', 'array'],
            'plans.*' => ['string', 'exists:plans,slug'],
            'workspaces' => ['nullable', 'array'],
            'workspaces.*' => ['integer', 'exists:workspaces,id'],
            'inactive_days' => ['nullable', 'integer', 'between:7,180'],
            'only_push' => ['nullable', 'boolean'],
            'also_email' => ['nullable', 'boolean'],
            'when' => ['required', Rule::in(['draft', 'now', 'later'])],
            'scheduled_at' => ['nullable', 'required_if:when,later', 'date', 'after:now'],
        ]);

        // Le lien d'une notification mène au site ou à une page sécurisée : un lien illisible est refusé plutôt qu'ignoré.
        $url = filled($data['url'] ?? null) ? $notifier->normalizeUrl($data['url']) : null;
        if (filled($data['url'] ?? null) && $url === null) {
            return back()->withInput()->withErrors(['url' => 'Le lien doit commencer par / (une page du site) ou par https://.']);
        }

        $audience = $this->audienceFrom($request);
        if ($audience['type'] === 'plans' && $audience['plans'] === []) {
            return back()->withInput()->withErrors(['plans' => 'Choisissez au moins une offre.']);
        }
        if ($audience['type'] === 'workspaces' && $audience['workspaces'] === []) {
            return back()->withInput()->withErrors(['workspaces' => 'Choisissez au moins une entreprise.']);
        }

        $campaign->fill([
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'url' => $url,
            'kind' => $data['kind'],
            'audience' => $audience,
            'also_email' => $request->boolean('also_email'),
            'created_by' => $campaign->created_by ?? $request->user()->id,
            'status' => $data['when'] === 'draft' ? PushCampaign::DRAFT : PushCampaign::SCHEDULED,
            'scheduled_at' => match ($data['when']) {
                'now' => now(),
                'later' => $data['scheduled_at'],
                default => null,
            },
        ])->save();

        if ($data['when'] === 'draft') {
            return redirect()->route('admin.notifications.edit', $campaign)->with('status', 'Brouillon enregistré.');
        }

        AuditLog::record($data['when'] === 'now' ? 'notification.sent' : 'notification.scheduled', $campaign->title, ['audience' => CampaignAudience::describe($audience), 'kind' => $campaign->kind]);

        if ($data['when'] === 'now') {
            // Un premier morceau part tout de suite ; le planificateur envoie la suite chaque minute.
            $sender->start($campaign);
            $sender->process($campaign, 15, (int) config('notifications.push.chunk', 60));
            $campaign->refresh();

            return redirect()->route('admin.notifications.show', $campaign)->with('status', $campaign->status === PushCampaign::SENT
                ? 'Notification envoyée.'
                : 'Envoi commencé : le reste part automatiquement dans les minutes qui viennent.');
        }

        return redirect()->route('admin.notifications.show', $campaign)->with('status', 'Notification programmée.');
    }

    /** @return array{type:string, plans:list<string>, workspaces:list<int>, days:int, only_push:bool} */
    private function audienceFrom(Request $request): array
    {
        $type = $request->input('audience_type', 'all');

        return [
            'type' => array_key_exists($type, CampaignAudience::TYPES) ? $type : 'all',
            'plans' => array_values(array_filter((array) $request->input('plans', []), 'is_string')),
            'workspaces' => array_values(array_map('intval', (array) $request->input('workspaces', []))),
            'days' => max(7, min(180, (int) $request->input('inactive_days', 14))),
            'only_push' => $request->boolean('only_push'),
        ];
    }

    /** @return array<string,mixed> */
    private function formData(PushCampaign $campaign): array
    {
        return [
            'campaign' => $campaign,
            'plans' => Plan::orderBy('sort')->get(['slug', 'name']),
            'workspaces' => Workspace::orderBy('name')->limit(300)->get(['id', 'name', 'plan']),
            'types' => CampaignAudience::TYPES,
            'quickLinks' => [
                '' => 'Aucun lien (ouvre la liste des notifications)',
                '/dashboard' => 'Tableau de bord',
                '/billing' => 'Abonnement et offres',
                '/bots' => 'Mes assistants',
                '/demandes' => 'Demandes à traiter',
                '/aide' => 'Aide et guides',
            ],
        ];
    }
}
