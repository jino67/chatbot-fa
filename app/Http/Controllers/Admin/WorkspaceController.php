<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UsageService;
use App\Support\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gestion des espaces clients par le personnel : creation (avec le compte du proprietaire), suivi,
 * suspension, changement d'offre, et « entree » dans l'espace pour gerer son contenu a la place du client.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request, UsageService $usage)
    {
        $search = trim((string) $request->query('q'));

        $workspaces = Workspace::with(['users' => fn ($q) => $q->where('role', User::CLIENT)])
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhereHas('users', fn ($u) => $u->where('email', 'like', "%{$search}%"))))
            ->when($request->query('plan'), fn ($q, $plan) => $q->where('plan', $plan))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.workspaces.index', [
            'workspaces' => $workspaces,
            'usage' => $workspaces->getCollection()->mapWithKeys(fn ($w) => [$w->id => $usage->summary($w)]),
            'plans' => Plan::orderBy('sort')->get(),
            'search' => $search,
        ]);
    }

    public function create()
    {
        return view('admin.workspaces.create', ['plans' => Plan::orderBy('sort')->get()]);
    }

    /** Cree l'espace et le compte de son proprietaire, avec un mot de passe provisoire affiche une seule fois. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'plan' => ['required', Rule::exists('plans', 'slug')],
            'country' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
        ]);

        // Une offre gratuite demarre toujours par un essai limite dans le temps.
        $plan = Plan::bySlug($data['plan']);

        $workspace = Workspace::create([
            'name' => $data['name'],
            'plan' => $data['plan'],
            'currency' => $data['currency'] ?? Currency::default(),
            'subscription_status' => $plan?->hasTrial() ? Workspace::TRIALING : Workspace::ACTIVE,
            'plan_ends_at' => $plan?->trialEndsAt(),
            'country' => $data['country'] ?? null,
            'phone' => $data['phone'] ?? null,
            'plan_started_at' => now(),
        ]);

        $password = Str::password(12, symbols: false);
        User::create(['name' => $data['owner_name'], 'email' => Str::lower($data['owner_email']), 'password' => $password, 'workspace_id' => $workspace->id]);

        AuditLog::record('workspace.created', $workspace->name, ['plan' => $data['plan']], $workspace->id);

        return redirect()->route('admin.workspaces.show', $workspace)
            ->with('status', "Espace « {$workspace->name} » créé.")
            ->with('new_password', ['email' => Str::lower($data['owner_email']), 'password' => $password]);
    }

    public function show(Workspace $workspace, UsageService $usage)
    {
        return view('admin.workspaces.show', [
            'workspace' => $workspace,
            'users' => $workspace->users()->orderBy('id')->get(),
            'bots' => Bot::withoutGlobalScopes()->where('workspace_id', $workspace->id)->withCount('sources')->get(),
            'usage' => $usage->summary($workspace),
            'payments' => Payment::withoutGlobalScopes()->with('recorder')->where('workspace_id', $workspace->id)->latest('paid_at')->limit(20)->get(),
            'plans' => Plan::orderBy('sort')->get(),
            'methods' => Payment::METHODS,
            'activity' => \App\Services\Analytics\ClientStats::today(30)->workspace($workspace->id),
        ]);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
        ]);
        $data['currency'] ??= $workspace->currency;

        $workspace->update($data);
        AuditLog::record('workspace.updated', $workspace->name, [], $workspace->id);

        return back()->with('status', 'Informations enregistrées.');
    }

    /** Entre dans l'espace du client : toutes les pages « client » portent alors sur cet espace. */
    public function enter(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->session()->put(User::ACTING_SESSION_KEY, $workspace->id);
        AuditLog::record('workspace.entered', $workspace->name, [], $workspace->id);

        return redirect()->route('dashboard');
    }

    public function leave(Request $request): RedirectResponse
    {
        $id = $request->session()->pull(User::ACTING_SESSION_KEY);

        return redirect()->route($id ? 'admin.workspaces.show' : 'admin.workspaces.index', $id ?: [])->with('status', "Vous avez quitté l'espace client.");
    }

    public function suspend(Request $request, Workspace $workspace): RedirectResponse
    {
        $suspend = ! $workspace->is_suspended;
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $workspace->update(['is_suspended' => $suspend, 'suspended_reason' => $suspend ? ($data['reason'] ?? null) : null]);
        AuditLog::record($suspend ? 'workspace.suspended' : 'workspace.reactivated', $workspace->name, ['reason' => $data['reason'] ?? null], $workspace->id);

        return back()->with('status', $suspend ? 'Espace suspendu : ses assistants ne répondent plus et le client ne peut plus utiliser son tableau de bord.' : 'Espace réactivé.');
    }

    /** Change l'offre sans paiement (geste commercial, essai, correction) ; les paiements passent par PaymentController. */
    /** Active ou retire une option à la carte (achetée par le client, hors de son offre). */
    public function addon(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate(['addon' => ['required', Rule::in(array_keys(config('platform.billing.addons')))], 'enabled' => ['required', 'boolean']]);

        $workspace->grantAddon($data['addon'], $request->boolean('enabled'));
        AuditLog::record('workspace.addon_changed', $workspace->name, ['addon' => $data['addon'], 'enabled' => $request->boolean('enabled')], $workspace->id);

        return back()->with('status', $request->boolean('enabled') ? 'Option activée.' : 'Option retirée.');
    }

    public function plan(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'slug')],
            'ends_at' => ['nullable', 'date'],
        ]);

        // Sans date de fin : une offre payante reste active ; l'offre gratuite recoit la duree d'essai par defaut.
        $plan = Plan::bySlug($data['plan']);

        $workspace->update([
            'plan' => $data['plan'],
            'plan_started_at' => now(),
            'plan_ends_at' => $data['ends_at'] ?? $plan?->trialEndsAt(),
            'subscription_status' => $plan?->hasTrial() ? Workspace::TRIALING : Workspace::ACTIVE,
        ]);

        AuditLog::record('workspace.plan_changed', $workspace->name, ['plan' => $data['plan'], 'ends_at' => $data['ends_at'] ?? null], $workspace->id);

        return back()->with('status', 'Offre mise à jour.');
    }
}
