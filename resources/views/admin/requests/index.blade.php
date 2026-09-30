<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-slate-800 leading-tight">Demandes d'activation WhatsApp</h2></x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach (['' => 'Toutes', 'requested' => 'Reçues', 'in_progress' => 'En cours', 'active' => 'Activées', 'rejected' => 'Refusées'] as $key => $label)
                    <a href="{{ route('admin.requests.index', ['status' => $key ?: null]) }}"
                       class="rounded-full px-3 py-1 text-sm {{ ($status ?? '') === $key ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 border border-slate-200' }}">{{ $label }}</a>
                @endforeach
            </div>

            <div class="surface divide-y divide-slate-100">
                @forelse ($requests as $req)
                    <a href="{{ route('admin.requests.show', $req->id) }}" class="flex items-center justify-between px-6 py-4 hover:bg-slate-50">
                        <div>
                            <div class="font-medium text-slate-900">{{ $req->business_name }} <span class="text-slate-400 font-normal">· {{ $req->phone_number }}</span></div>
                            <div class="text-sm text-slate-500">{{ $req->workspace->name }} · {{ $req->country ?: 'pays non précisé' }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <x-badge :tone="match($req->status) { 'active' => 'green', 'rejected' => 'red', 'in_progress' => 'blue', default => 'amber' }">{{ $req->statusLabel() }}</x-badge>
                            <span class="text-xs text-slate-400">{{ $req->created_at->format('d/m/Y') }}</span>
                        </div>
                    </a>
                @empty
                    <div class="px-6 py-12 text-center text-sm text-slate-500">Aucune demande.</div>
                @endforelse
            </div>
            <div class="mt-4">{{ $requests->links() }}</div>
        </div>
    </div>
</x-app-layout>
