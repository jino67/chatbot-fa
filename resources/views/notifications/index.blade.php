@php
    $icons = ['leads' => 'inbox', 'handoffs' => 'users', 'account' => 'card', 'system' => 'bell', 'promo' => 'megaphone'];
@endphp
<x-app-layout title="Notifications | {{ $brand['name'] }}">
    <x-slot name="header">
        <x-page-header title="Notifications" :subtitle="$unread > 0 ? $unread.' non lue(s)' : 'Tout est lu. Vous serez prévenu ici dès qu\'il se passe quelque chose.'">
            <x-slot name="actions">
                @if ($unread > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}">@csrf
                        <button class="btn-outline"><x-icon name="check" class="h-4 w-4" /> Tout marquer comme lu</button>
                    </form>
                @endif
                <a href="{{ route('notifications.preferences') }}" class="btn-outline"><x-icon name="cog" class="h-4 w-4" /> Préférences</a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <x-push-card />

        <div class="flex gap-1.5" role="group" aria-label="Filtre">
            @foreach (['toutes' => 'Toutes', 'non-lues' => 'Non lues'] as $key => $label)
                <a href="{{ route('notifications.index', ['filtre' => $key]) }}" @class(['rounded-full px-4 py-1.5 text-sm font-medium transition', 'bg-brand-600 text-white' => $filter === $key, 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-brand-50' => $filter !== $key])>{{ $label }}</a>
            @endforeach
        </div>

        <section class="surface divide-y divide-slate-100 overflow-hidden">
            @forelse ($notifications as $item)
                <a href="{{ route('notifications.open', $item->id) }}" class="flex gap-4 px-5 py-4 transition hover:bg-slate-50">
                    <span class="mt-0.5 grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $item->isRead() ? 'bg-slate-100 text-slate-500' : 'bg-brand-50 text-brand-700' }}"><x-icon :name="$icons[$item->category] ?? 'bell'" class="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-start justify-between gap-3">
                            <span class="{{ $item->isRead() ? 'text-slate-700' : 'font-semibold text-brand-950' }}">{{ $item->title }}</span>
                            <span class="shrink-0 text-xs text-slate-400">{{ $item->created_at->locale('fr')->diffForHumans() }}</span>
                        </span>
                        @if ($item->body) <span class="mt-0.5 block text-sm text-slate-600">{{ $item->body }}</span> @endif
                        <span class="mt-1.5 flex items-center gap-2 text-xs text-slate-400">
                            <x-badge>{{ $item->categoryLabel() }}</x-badge>
                            @unless ($item->isRead()) <span class="inline-flex items-center gap-1 text-brand-700"><span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span> Non lue</span> @endunless
                        </span>
                    </span>
                </a>
            @empty
                <div class="px-6 py-14 text-center">
                    <x-icon name="bell" class="mx-auto h-9 w-9 text-slate-300" />
                    <p class="mt-3 font-display text-lg font-bold text-brand-950">{{ $filter === 'non-lues' ? 'Rien de nouveau' : 'Aucune notification pour le moment' }}</p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-600">Les commandes, les demandes de vos clients, les échéances d'abonnement et les nouveautés de {{ $brand['name'] }} apparaîtront ici.</p>
                </div>
            @endforelse
        </section>

        <div>{{ $notifications->links() }}</div>
    </div>
</x-app-layout>
