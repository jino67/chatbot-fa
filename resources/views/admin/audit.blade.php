@use('App\Support\AuditCatalog')
@php
    $keep = fn (array $changes = []) => array_filter(array_merge(request()->only(['categorie', 'personne', 'client', 'q', 'niveau', 'jours']), $changes), fn ($v) => $v !== null && $v !== '');
    $pill = fn (bool $on) => $on ? 'bg-brand-600 text-white shadow-sm' : 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-brand-50 hover:text-brand-800';
    $days = (int) request('jours', 30);
    $tone = ['critique' => 'red', 'sensible' => 'amber', 'normal' => 'gray'];
@endphp
<x-app-layout title="Journal | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Journal d'activité" subtitle="Qui a fait quoi, quand et dans quel espace : changements de réglages, de rôles, d'offres, entrées chez un client, lecture de conversations.">
            <x-slot name="actions">
                <a href="{{ route('admin.audit.export', $keep()) }}" class="btn-outline"><x-icon name="download" class="h-4 w-4" /> Exporter en CSV</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Période">
                @foreach ($periods as $d => $label)
                    <a href="{{ route('admin.audit.index', $keep(['jours' => $d === 30 ? null : $d])) }}" @class(['rounded-full px-3.5 py-1.5 text-xs font-semibold transition', $pill($days === $d)])>{{ $label }}</a>
                @endforeach
            </div>
            <p class="text-sm text-slate-600">
                <strong class="text-brand-950">{{ number_format($total, 0, ',', ' ') }}</strong> action(s) par <strong class="text-brand-950">{{ $peopleCount }}</strong> personne(s),
                dont <a href="{{ route('admin.audit.index', $keep(['niveau' => request('niveau') === 'sensible' ? null : 'sensible'])) }}" class="font-semibold {{ $sensitiveCount ? 'text-accent-700' : 'text-slate-700' }} underline">{{ number_format($sensitiveCount, 0, ',', ' ') }} sensible(s)</a>
            </p>
        </div>

        <form method="GET" class="surface grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
            <input type="hidden" name="jours" value="{{ $days }}">
            <div class="lg:col-span-2">
                <label for="q" class="text-xs font-medium text-slate-600">Rechercher (cible ou action)</label>
                <input id="q" name="q" value="{{ request('q') }}" maxlength="80" class="field" placeholder="ex. Boutique Awa">
            </div>
            <div>
                <label for="categorie" class="text-xs font-medium text-slate-600">Rubrique</label>
                <select id="categorie" name="categorie" class="field">
                    <option value="">Toutes</option>
                    @foreach ($categories as $key => [$label])
                        <option value="{{ $key }}" @selected(request('categorie') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="personne" class="text-xs font-medium text-slate-600">Personne</label>
                <select id="personne" name="personne" class="field">
                    <option value="">Toutes</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected((int) request('personne') === $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="client" class="text-xs font-medium text-slate-600">Espace</label>
                <select id="client" name="client" class="field">
                    <option value="">Tous</option>
                    @foreach ($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected((int) request('client') === $workspace->id)>{{ $workspace->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end gap-3">
                <label class="flex items-center gap-2 pb-2 text-sm text-slate-700"><input type="checkbox" name="niveau" value="sensible" @checked(request('niveau') === 'sensible') class="rounded border-slate-300"> Sensibles</label>
            </div>
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-6">
                <button class="btn-primary">Filtrer</button>
                @if ($filtered) <a href="{{ route('admin.audit.index', ['jours' => $days === 30 ? null : $days]) }}" class="btn-outline">Tout effacer</a> @endif
                <span class="ms-auto text-sm text-slate-600">{{ number_format($logs->total(), 0, ',', ' ') }} ligne(s)</span>
            </div>
        </form>

        <div class="surface overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-slate-500">
                    <tr><th class="px-5 py-3 font-medium">Date</th><th class="px-5 py-3 font-medium">Personne</th><th class="px-5 py-3 font-medium">Action</th><th class="px-5 py-3 font-medium">Cible</th><th class="px-5 py-3 font-medium">Espace</th><th class="px-5 py-3 font-medium">Détail</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        @php $level = AuditCatalog::level($log->action); @endphp
                        <tr @class(['align-top', 'bg-red-50/40' => $level === 'critique'])>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-600" title="{{ $log->created_at->format('d/m/Y H:i:s') }}">
                                {{ $log->created_at->format('d/m/Y H:i') }}
                                <span class="block text-xs text-slate-400">{{ $log->created_at->locale('fr')->diffForHumans() }}</span>
                            </td>
                            <td class="px-5 py-3">
                                {{ $log->user?->name ?? 'Système' }}
                                @if ($log->ip) <span class="block text-xs text-slate-400">{{ $log->ip }}</span> @endif
                            </td>
                            <td class="px-5 py-3">
                                <span class="font-medium text-slate-900">{{ AuditCatalog::label($log->action) }}</span>
                                @if ($level !== 'normal') <x-badge :tone="$tone[$level]" class="ms-1">{{ $level }}</x-badge> @endif
                                <span class="block font-mono text-xs text-slate-500">{{ $log->action }}</span>
                            </td>
                            <td class="px-5 py-3">
                                @if (str_starts_with($log->action, 'chat.') && ($log->meta['conversation'] ?? null))
                                    <a href="{{ route('admin.chats.show', $log->meta['conversation']) }}" class="text-brand-700 underline">{{ $log->subject }}</a>
                                @else
                                    {{ $log->subject }}
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                @if ($log->workspace) <a href="{{ route('admin.workspaces.show', $log->workspace) }}" class="text-brand-600 hover:text-brand-800">{{ $log->workspace->name }}</a> @endif
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-600">
                                @foreach (AuditCatalog::details($log->meta) as $line) <span class="block">{{ $line }}</span> @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-12 text-center text-slate-600">Aucune activité ne correspond à ces choix.@if ($days > 0) Essayez « Tout » pour élargir la période.@endif</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    </div>
</x-app-layout>
