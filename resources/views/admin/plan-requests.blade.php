<x-app-layout title="Demandes d'offre | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Demandes d'offre" subtitle="Un client souhaite changer d'offre. Encaissez son paiement depuis sa fiche : la demande est alors clôturée et l'offre activée." />
    </x-slot>

    <div class="mx-auto max-w-5xl space-y-5 px-4 py-8 sm:px-6 lg:px-8">
        <div class="flex flex-wrap gap-2">
            @foreach (['requested' => 'À traiter', 'approved' => 'Traitées', 'rejected' => 'Refusées', '' => 'Toutes'] as $key => $label)
                <a href="{{ route('admin.plan-requests.index', ['status' => $key ?: '']) }}"
                   class="rounded-full px-3 py-1 text-sm {{ (string) $status === (string) $key ? 'bg-brand-600 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' }}">{{ $label }}</a>
            @endforeach
        </div>

        <div class="surface divide-y divide-slate-100">
            @forelse ($requests as $req)
                <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4">
                    <div class="min-w-0">
                        <a href="{{ route('admin.workspaces.show', $req->workspace_id) }}" class="font-semibold text-brand-950 hover:text-brand-700">{{ $req->workspace?->name }}</a>
                        <p class="text-sm text-slate-600">
                            Offre souhaitée : <strong>{{ $req->plan }}</strong>, demandée par {{ $req->requester?->name }} ({{ $req->requester?->email }}), {{ $req->created_at->diffForHumans() }}
                        </p>
                        @if ($req->message) <p class="mt-1 text-sm text-slate-700">« {{ $req->message }} »</p> @endif
                        @if ($req->admin_notes) <p class="mt-1 text-sm text-slate-500">Note : {{ $req->admin_notes }}</p> @endif
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($req->status === 'requested')
                            <a href="{{ route('admin.workspaces.show', $req->workspace_id) }}" class="btn-primary">Encaisser</a>
                            <form method="POST" action="{{ route('admin.plan-requests.update', $req) }}" class="flex items-center gap-2">
                                @csrf @method('PUT')
                                <input type="hidden" name="status" value="rejected">
                                <input name="admin_notes" class="field !mt-0 !w-40" placeholder="Motif" aria-label="Motif du refus">
                                <button class="text-sm font-medium text-red-600 hover:text-red-800">Refuser</button>
                            </form>
                        @else
                            <x-badge :tone="$req->status === 'approved' ? 'green' : 'red'">{{ $req->status === 'approved' ? 'Traitée' : 'Refusée' }}</x-badge>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-6 py-12 text-center text-sm text-slate-600">Aucune demande.</p>
            @endforelse
        </div>
        {{ $requests->links() }}
    </div>
</x-app-layout>
