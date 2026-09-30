<x-bot-layout :bot="$bot" tab="conversations">
    @php
        $filters = ['' => 'Toutes', 'needs_human' => 'À traiter', 'human' => 'Prises en main', 'bot' => 'Gérées par le bot', 'closed' => 'Clôturées'];
        $labels = ['bot' => ['Bot', 'gray'], 'needs_human' => ['À traiter', 'red'], 'human' => ['Humain', 'indigo'], 'closed' => ['Clôturée', 'gray']];
    @endphp

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($filters as $key => $label)
            <a href="{{ route('conversations.index', [$bot, 'status' => $key ?: null]) }}"
               class="rounded-full px-3 py-1 text-sm {{ ($status ?? '') === $key ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 border border-slate-200 hover:border-slate-300' }}">
                {{ $label }}@if ($key !== '') <span class="opacity-70">({{ $counts[$key] ?? 0 }})</span>@endif
            </a>
        @endforeach
    </div>

    <div class="surface divide-y divide-slate-100">
        @forelse ($conversations as $conversation)
            @php [$label, $tone] = $labels[$conversation->status] ?? [$conversation->status, 'gray']; @endphp
            <a href="{{ route('conversations.show', [$bot, $conversation]) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-slate-900">{{ $conversation->displayName() }}</span>
                        <x-badge :tone="$tone">{{ $label }}</x-badge>
                        <x-badge>{{ $conversation->channel === 'whatsapp' ? 'WhatsApp' : 'Site web' }}</x-badge>
                    </div>
                    <div class="mt-1 truncate text-sm text-slate-500">{{ \Illuminate\Support\Str::limit($conversation->last_text, 110) }}</div>
                </div>
                <div class="shrink-0 text-xs text-slate-400">{{ $conversation->last_message_at?->diffForHumans() }}</div>
            </a>
        @empty
            <div class="px-6 py-12 text-center text-sm text-slate-500">Aucune conversation pour l'instant.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $conversations->links() }}</div>
</x-bot-layout>
