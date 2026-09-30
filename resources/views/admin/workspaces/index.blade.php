<x-app-layout title="Espaces clients | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Espaces clients" subtitle="Créez, suivez et gérez les espaces de vos clients. Entrez dans un espace pour modifier son contenu à leur place.">
            <x-slot name="actions">
                <a href="{{ route('admin.workspaces.create') }}" class="btn-primary"><x-icon name="building" class="h-4 w-4" /> Nouvel espace client</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="min-w-[14rem] flex-1">
                <label for="q" class="text-sm font-medium text-slate-700">Rechercher</label>
                <input id="q" name="q" value="{{ $search }}" class="field" placeholder="Nom de l'entreprise ou e-mail">
            </div>
            <div>
                <label for="plan" class="text-sm font-medium text-slate-700">Offre</label>
                <select id="plan" name="plan" class="field">
                    <option value="">Toutes</option>
                    @foreach ($plans as $p)
                        <option value="{{ $p->slug }}" @selected(request('plan') === $p->slug)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn-outline">Filtrer</button>
        </form>

        <div class="surface overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-slate-500">
                    <tr>
                        <th class="px-5 py-3 font-medium">Entreprise</th>
                        <th class="px-5 py-3 font-medium">Offre</th>
                        <th class="px-5 py-3 font-medium">Consommation</th>
                        <th class="px-5 py-3 font-medium">Échéance</th>
                        <th class="px-5 py-3 font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($workspaces as $ws)
                        @php $u = $usage[$ws->id]; $owner = $ws->users->first(); @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-3">
                                <a href="{{ route('admin.workspaces.show', $ws) }}" class="font-semibold text-brand-950 hover:text-brand-700">{{ $ws->name }}</a>
                                <p class="text-xs text-slate-500">{{ $owner?->email ?? 'sans propriétaire' }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-badge :tone="$ws->isPaid() ? 'brand' : 'gray'">{{ $ws->planModel()?->name }}</x-badge>
                                    @if ($ws->is_suspended) <x-badge tone="red">Suspendu</x-badge>
                                    @elseif ($ws->subscription_status === 'past_due') <x-badge tone="amber">En retard</x-badge> @endif
                                </div>
                            </td>
                            <td class="px-5 py-3 text-slate-700">
                                {{ $u['messages']['used'] }} / {{ $u['messages']['limit'] }} réponses<br>
                                <span class="text-xs text-slate-500">{{ $u['bots']['used'] }} assistant(s), {{ $u['sources']['used'] }} source(s)</span>
                            </td>
                            <td class="px-5 py-3 text-slate-700">{{ $ws->plan_ends_at?->format('d/m/Y') ?? 'Sans échéance' }}</td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('admin.workspaces.enter', $ws) }}" class="inline">@csrf
                                    <button class="btn-outline !px-3 !py-1.5 text-xs">Entrer dans l'espace</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-12 text-center text-slate-600">Aucun espace ne correspond. <a href="{{ route('admin.workspaces.create') }}" class="font-medium text-brand-600 underline">Créer un espace client</a></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $workspaces->links() }}
    </div>
</x-app-layout>
