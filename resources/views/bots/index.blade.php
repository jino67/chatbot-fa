<x-app-layout title="Assistants | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Assistants" subtitle="Chaque assistant a sa propre mémoire, sa personnalité et ses canaux.">
            <x-slot name="actions">
                <a href="{{ route('bots.create') }}" class="btn-primary"><x-icon name="chat" class="h-4 w-4" /> Nouvel assistant</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="surface divide-y divide-slate-100">
            @forelse ($bots as $bot)
                <a href="{{ route('sources.index', $bot) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-brand-950">{{ $bot->name }}</p>
                        <p class="text-sm text-slate-600">{{ $bot->sources_count }} source(s), langue : {{ strtoupper($bot->language) }}@if ($bot->sector), {{ config('sectors.'.$bot->sector.'.label') }}@endif</p>
                    </div>
                    @if ($bot->is_active) <x-badge tone="green">Actif</x-badge> @else <x-badge tone="amber">En pause</x-badge> @endif
                </a>
            @empty
                <div class="p-10 text-center">
                    <p class="text-sm text-slate-600">Aucun assistant pour le moment.</p>
                    <a href="{{ route('bots.create') }}" class="btn-primary mt-4">Créer mon premier assistant</a>
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
