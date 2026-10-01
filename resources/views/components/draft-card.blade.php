{{-- Rappel d'une création d'assistant en cours : « Reprendre » rouvre le formulaire tel que la personne l'a laissé. --}}
@props(['draft'])
@if ($draft)
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl rounded-bl-md border border-brand-200 bg-brand-50 px-5 py-4 text-sm">
        <div class="min-w-0">
            <p class="font-semibold text-brand-950">Création en cours : « {{ $draft['fields']['name'] ?? 'Nouvel assistant' }} »</p>
            <p class="mt-0.5 text-slate-600">Enregistrée {{ \Carbon\Carbon::parse($draft['saved_at'])->diffForHumans() }}. Rien n'est perdu : reprenez où vous vous étiez arrêté.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('bots.create') }}" class="btn-primary">Reprendre la création</a>
            <form method="POST" action="{{ route('bots.draft.discard') }}" onsubmit="return confirm('Supprimer ce brouillon ?')">@csrf @method('DELETE')
                <button class="rounded-full px-3 py-2 text-sm font-medium text-slate-600 hover:bg-white">Supprimer</button>
            </form>
        </div>
    </div>
@endif
