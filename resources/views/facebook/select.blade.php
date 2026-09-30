<x-app-layout title="Choisir la page Facebook | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Quelle page connecter ?" :subtitle="'Assistant : '.$bot->name.'. Son contenu sera importé, puis relu chaque semaine.'" />
    </x-slot>

    <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
        <form method="POST" action="{{ route('facebook.select', $bot) }}" class="surface divide-y divide-slate-100">
            @csrf
            @foreach ($pages as $page)
                <label class="flex cursor-pointer items-center gap-4 px-5 py-4 hover:bg-slate-50">
                    <input type="radio" name="page_id" value="{{ $page['id'] }}" required class="border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>
                        <span class="block font-semibold text-brand-950">{{ $page['name'] }}</span>
                        @if ($page['category']) <span class="block text-sm text-slate-600">{{ $page['category'] }}</span> @endif
                    </span>
                </label>
            @endforeach
            <div class="flex items-center justify-between gap-3 bg-slate-50 px-5 py-4">
                <a href="{{ route('sources.index', $bot) }}" class="text-sm text-slate-600 hover:text-brand-700">Annuler</a>
                <button class="btn-primary">Connecter cette page</button>
            </div>
        </form>
    </div>
</x-app-layout>
