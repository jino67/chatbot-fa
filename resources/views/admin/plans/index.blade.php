<x-app-layout title="Offres et tarifs | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Offres et tarifs" subtitle="Prix, quotas et options de chaque abonnement. Les changements s'appliquent tout de suite, sans toucher au code.">
            <x-slot name="actions">
                <a href="{{ route('admin.plans.create') }}" class="btn-primary"><x-icon name="layers" class="h-4 w-4" /> Nouvelle offre</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="surface overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-slate-500">
                    <tr>
                        <th class="px-5 py-3 font-medium">Offre</th>
                        <th class="px-5 py-3 font-medium">Prix</th>
                        <th class="px-5 py-3 font-medium">Quotas</th>
                        <th class="px-5 py-3 font-medium">Options</th>
                        <th class="px-5 py-3 font-medium">Espaces</th>
                        <th class="px-5 py-3 font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($plans as $plan)
                        <tr class="align-top">
                            <td class="px-5 py-4">
                                <p class="font-semibold text-brand-950">{{ $plan->name }} <span class="font-mono text-xs font-normal text-slate-500">{{ $plan->slug }}</span></p>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    @if ($plan->is_default) <x-badge tone="green">Par défaut</x-badge> @endif
                                    @if ($plan->is_highlighted) <x-badge tone="brand">Mise en avant</x-badge> @endif
                                    @unless ($plan->is_public) <x-badge>Masquée</x-badge> @endunless
                                </div>
                            </td>
                            <td class="px-5 py-4 text-slate-800">{{ $plan->allPrices() }}@unless ($plan->isFree()) <span class="text-xs text-slate-500">/ {{ $plan->period_months }} mois</span>@elseif ($plan->hasTrial()) <span class="text-xs text-slate-500">{{ $plan->periodLabel() }}</span>@endunless</td>
                            <td class="px-5 py-4 text-slate-700">
                                {{ $plan->limit('bots') }} assistant(s), {{ number_format($plan->limit('messages_per_month'), 0, ',', ' ') }} réponses/mois<br>
                                <span class="text-xs text-slate-500">{{ $plan->limit('sources') }} sources, {{ $plan->limit('pages_per_crawl') }} pages/site, {{ $plan->limit('members') }} utilisateur(s)</span>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-700">
                                @foreach (\App\Models\Plan::featureFields() as $f)
                                    <span class="{{ $plan->feature($f['key']) ? 'text-emerald-700' : 'text-slate-400 line-through' }}">{{ $f['label'] }}</span>@unless ($loop->last)<br>@endunless
                                @endforeach
                            </td>
                            <td class="px-5 py-4">{{ $counts[$plan->slug] ?? 0 }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('admin.plans.edit', $plan) }}" class="font-medium text-brand-600 hover:text-brand-800">Modifier</a>
                                @unless ($plan->is_default)
                                    <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}" class="ms-3 inline" onsubmit="return confirm('Supprimer cette offre ?')">
                                        @csrf @method('DELETE')
                                        <button class="font-medium text-red-600 hover:text-red-800">Supprimer</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
