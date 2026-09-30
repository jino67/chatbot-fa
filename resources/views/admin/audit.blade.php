<x-app-layout title="Journal | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Journal d'activité" subtitle="Qui a fait quoi, surtout quand le personnel agit dans l'espace d'un client." />
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="action" class="text-sm font-medium text-slate-700">Type d'action</label>
                <select id="action" name="action" class="field">
                    <option value="">Toutes</option>
                    @foreach (['workspace' => 'Espaces clients', 'payment' => 'Paiements', 'plan' => 'Offres', 'ai' => 'IA et fournisseurs', 'user' => 'Utilisateurs', 'team' => 'Équipe', 'settings' => 'Paramètres', 'template' => 'Modèles WhatsApp', 'billing' => 'Abonnement'] as $key => $label)
                        <option value="{{ $key }}" @selected(request('action') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn-outline">Filtrer</button>
        </form>

        <div class="surface overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-slate-500">
                    <tr><th class="px-5 py-3 font-medium">Date</th><th class="px-5 py-3 font-medium">Personne</th><th class="px-5 py-3 font-medium">Action</th><th class="px-5 py-3 font-medium">Cible</th><th class="px-5 py-3 font-medium">Espace</th><th class="px-5 py-3 font-medium">Détail</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-5 py-3 text-slate-600">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-5 py-3">{{ $log->user?->name ?? 'Système' }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-brand-800">{{ $log->action }}</td>
                            <td class="px-5 py-3">{{ $log->subject }}</td>
                            <td class="px-5 py-3">
                                @if ($log->workspace) <a href="{{ route('admin.workspaces.show', $log->workspace) }}" class="text-brand-600 hover:text-brand-800">{{ $log->workspace->name }}</a> @endif
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-600">{{ $log->meta ? json_encode($log->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-12 text-center text-slate-600">Aucune activité enregistrée.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    </div>
</x-app-layout>
