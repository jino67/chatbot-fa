@props(['bot', 'tab'])
@php
    $waiting = $bot->conversations()->real()->where('status', 'needs_human')->count();
    $tabs = [
        'sources' => ['Connaissances', route('sources.index', $bot)],
        'instructions' => ['Personnalité', route('instructions.edit', $bot)],
        'playground' => ['Tester', route('playground.show', $bot)],
        'conversations' => ['Conversations', route('conversations.index', $bot)],
        'analytics' => ['Analytique', route('analytics.show', $bot)],
        'channels' => ['Canaux', route('channels.show', $bot)],
        'import' => ['Import WhatsApp', route('import.show', $bot)],
        'settings' => ['Réglages', route('bots.edit', $bot)],
    ];
@endphp
<x-app-layout :title="$bot->name.' | '.$brand['name']">
    <x-slot name="header">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ route('bots.index') }}" class="text-sm text-slate-500 hover:text-brand-700">Tous les assistants</a>
                <h1 class="mt-1 flex items-center gap-3 font-display text-2xl font-bold text-brand-950">
                    {{ $bot->name }}
                    @unless ($bot->is_active) <x-badge tone="amber">En pause</x-badge> @endunless
                </h1>
            </div>
            <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
                <button type="button" @click="open = ! open" :aria-expanded="open" class="btn-primary text-sm">
                    <x-icon name="link" class="h-4 w-4" /> Partager le lien
                </button>
                <div x-show="open" x-cloak x-transition.opacity class="absolute left-0 z-30 mt-2 w-[22rem] sm:left-auto sm:right-0 max-w-[calc(100vw-2rem)] rounded-2xl bg-white p-4 shadow-xl ring-1 ring-slate-200">
                    <x-share-chat :bot="$bot" variant="menu" />
                </div>
            </div>
        </div>
        <nav class="-mb-6 mt-5 flex gap-1 overflow-x-auto" aria-label="Sections de l'assistant">
            @foreach ($tabs as $key => [$label, $url])
                <a href="{{ $url }}" @if ($tab === $key) aria-current="page" @endif
                   class="whitespace-nowrap border-b-2 px-3 pb-3 pt-1 text-sm font-medium {{ $tab === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-brand-900' }}">
                    {{ $label }}
                    @if ($key === 'conversations' && $waiting > 0)
                        <span class="ms-1 rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $waiting }}</span>
                    @endif
                </a>
            @endforeach
        </nav>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    </div>
</x-app-layout>
