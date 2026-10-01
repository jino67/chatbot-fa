@php
    $workspace = $user?->currentWorkspace();
    $staffOverview = $user?->isStaff() && ! $workspace;

    $clientNav = [
        ['Tableau de bord', 'dashboard', 'home', 'dashboard'],
        ['Assistants', 'bots.index', 'chat', 'bots.*|sources.*|playground.*|conversations.*|analytics.*|channels.*|instructions.*|templates.*'],
        ['Demandes', 'leads.index', 'inbox', 'leads.*'],
        ['Alertes', 'alerts.edit', 'bolt', 'alerts.*'],
        ['Abonnement', 'billing.show', 'card', 'billing.*'],
    ];

    if ($workspace?->hasFeature('api')) {
        $clientNav[] = ['Développeurs', 'api-keys.index', 'chip', 'api-keys.*'];
    }

    $staffNav = [
        ['Vue d\'ensemble', 'admin.overview', 'home', 'admin.overview'],
        ['Espaces clients', 'admin.workspaces.index', 'building', 'admin.workspaces.*'],
        ['Demandes WhatsApp', 'admin.requests.index', 'inbox', 'admin.requests.*'],
        ['Demandes d\'offre', 'admin.plan-requests.index', 'card', 'admin.plan-requests.*'],
    ];

    $superNav = [
        ['Consommation', 'admin.consumption.index', 'chart', 'admin.consumption.*'],
        ['Offres et tarifs', 'admin.plans.index', 'layers', 'admin.plans.*'],
        ['IA et fournisseurs', 'admin.ai.index', 'chip', 'admin.ai.*'],
        ['Équipe', 'admin.team.index', 'users', 'admin.team.*'],
        ['Paramètres', 'admin.settings.edit', 'cog', 'admin.settings.*'],
        ['Journal', 'admin.audit.index', 'scroll', 'admin.audit.*'],
    ];

    $link = function (array $item) {
        [$label, $route, $icon, $match] = $item;
        $active = request()->routeIs(...explode('|', $match));

        return [$label, route($route), $icon, $active];
    };
@endphp

{{-- Fond assombri sur mobile --}}
<div x-show="menu" x-cloak @click="menu = false" class="fixed inset-0 z-30 bg-brand-950/60 lg:hidden"></div>

<aside :class="menu ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="sidebar-shell wax-navy fixed inset-y-0 left-0 z-40 flex w-64 flex-col text-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:shrink-0">
    <div class="flex h-16 items-center justify-between px-5">
        <a href="{{ route('dashboard') }}"><x-logo tone="light" /></a>
        <button type="button" @click="menu = false" class="rounded-lg p-1.5 text-white/70 hover:bg-white/10 lg:hidden" aria-label="Fermer le menu"><x-icon name="x" /></button>
    </div>

    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4" aria-label="Navigation principale">
        @if ($staffOverview || ($user?->isStaff()))
            <div>
                <p class="px-3 pb-2 text-xs font-medium text-white/50">{{ $user->isSuperAdmin() ? 'Super admin' : 'Équipe' }}</p>
                <ul class="space-y-0.5">
                    @foreach ($staffNav as $item)
                        @php [$label, $href, $icon, $active] = $link($item); @endphp
                        <li>
                            <a href="{{ $href }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-all duration-200 hover:translate-x-0.5', 'relative bg-white/15 text-white ring-1 ring-inset ring-white/10 before:absolute before:-left-3 before:top-1/2 before:h-5 before:w-1 before:-translate-y-1/2 before:rounded-full before:bg-accent-400' => $active, 'text-white/75 hover:bg-white/10 hover:text-white' => ! $active])>
                                <x-icon :name="$icon" class="h-[18px] w-[18px] {{ $active ? 'text-accent-400' : '' }}" /> {{ $label }}
                            </a>
                        </li>
                    @endforeach
                    @if ($user->isSuperAdmin())
                        @foreach ($superNav as $item)
                            @php [$label, $href, $icon, $active] = $link($item); @endphp
                            <li>
                                <a href="{{ $href }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-all duration-200 hover:translate-x-0.5', 'relative bg-white/15 text-white ring-1 ring-inset ring-white/10 before:absolute before:-left-3 before:top-1/2 before:h-5 before:w-1 before:-translate-y-1/2 before:rounded-full before:bg-accent-400' => $active, 'text-white/75 hover:bg-white/10 hover:text-white' => ! $active])>
                                    <x-icon :name="$icon" class="h-[18px] w-[18px] {{ $active ? 'text-accent-400' : '' }}" /> {{ $label }}
                                </a>
                            </li>
                        @endforeach
                    @endif
                </ul>
            </div>
        @endif

        @if ($workspace)
            <div>
                <p class="px-3 pb-2 text-xs font-medium text-white/50">{{ $user->isStaff() ? 'Espace : '.$workspace->name : 'Mon espace' }}</p>
                <ul class="space-y-0.5">
                    @foreach ($clientNav as $item)
                        @php [$label, $href, $icon, $active] = $link($item); @endphp
                        <li>
                            <a href="{{ $href }}" @class(['flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-all duration-200 hover:translate-x-0.5', 'relative bg-white/15 text-white ring-1 ring-inset ring-white/10 before:absolute before:-left-3 before:top-1/2 before:h-5 before:w-1 before:-translate-y-1/2 before:rounded-full before:bg-accent-400' => $active, 'text-white/75 hover:bg-white/10 hover:text-white' => ! $active])>
                                <x-icon :name="$icon" class="h-[18px] w-[18px] {{ $active ? 'text-accent-400' : '' }}" /> {{ $label }}
                                @if ($label === 'Demandes' && ($newLeads = \App\Models\Lead::where('status', 'new')->count()) > 0)
                                    <span class="ms-auto grid h-5 min-w-5 place-items-center rounded-full bg-hibiscus-500 px-1.5 text-[11px] font-bold text-white">{{ $newLeads }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </nav>

    <div class="border-t border-white/10 p-3">
        @if ($workspace && ! $user->isStaff())
            @php
                $plan = $workspace->planModel();
                $used = app(\App\Services\UsageService::class)->messagesThisMonth($workspace);
                $limit = max(1, $workspace->limit('messages_per_month'));
                $pct = min(100, (int) round(100 * $used / $limit));
            @endphp
            <a href="{{ route('billing.show') }}" class="mb-3 block rounded-lg bg-white/10 p-3 hover:bg-white/15">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-semibold">Offre {{ $plan?->name }}</span>
                    @if ($plan?->isFree()) <span class="rounded-full bg-accent-500 px-2 py-0.5 text-[11px] font-semibold text-brand-950">Améliorer</span> @endif
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/15">
                    <div class="bar-fill h-full rounded-full {{ $pct >= 90 ? 'bg-red-400' : 'bg-accent-400' }}" style="width: {{ $pct }}%"></div>
                </div>
                <p class="mt-1.5 text-xs text-white/60">{{ $used }} réponses sur {{ $limit }} ce mois-ci</p>
            </a>
        @endif

        <div class="flex items-center gap-3 rounded-lg px-2 py-1.5">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-500 text-sm font-bold text-brand-950">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                <p class="truncate text-xs text-white/60">{{ $user->roleLabel() }}</p>
            </div>
            <a href="{{ route('profile.edit') }}" class="rounded-md p-1.5 text-white/70 hover:bg-white/10 hover:text-white" title="Mon profil"><x-icon name="cog" class="h-[18px] w-[18px]" /></a>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button class="rounded-md p-1.5 text-white/70 hover:bg-white/10 hover:text-white" title="Se déconnecter"><x-icon name="logout" class="h-[18px] w-[18px]" /></button>
            </form>
        </div>
    </div>
</aside>
