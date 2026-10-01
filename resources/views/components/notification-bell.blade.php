{{--
    La cloche du centre de notifications : compteur, aperçu des dernières notifications, lien vers la liste et les préférences.
    Mise à jour toutes les minutes et dès qu'une notification arrive pendant que l'application est ouverte (resources/js/push.js).
    tone : « light » sur le fond marine du menu, « dark » sur la barre blanche du téléphone.
--}}
@props(['tone' => 'light'])
@php
    // Si la table des notifications n'existe pas encore (mise à jour de la base pas encore importée), la page reste utilisable.
    try {
        $unread = auth()->user()?->unreadNotificationCount() ?? 0;
    } catch (\Throwable $e) {
        report($e);
        $unread = 0;
    }
@endphp
<div x-data="notifBell(@js(route('notifications.summary')), {{ $unread }})" x-init="init()" @keydown.escape.window="open = false" @click.outside="open = false" class="relative">
    <button type="button" @click="toggle()" :aria-expanded="open" aria-haspopup="true"
            class="relative grid h-10 w-10 place-items-center rounded-xl transition {{ $tone === 'light' ? 'text-white/80 hover:bg-white/10 hover:text-white' : 'text-brand-900 hover:bg-slate-100' }}"
            :aria-label="unread > 0 ? 'Notifications, ' + unread + ' non lue(s)' : 'Notifications'">
        <x-icon name="bell" class="h-[22px] w-[22px]" />
        <span x-show="unread > 0" x-cloak x-text="unread > 99 ? '99+' : unread"
              class="absolute -right-0.5 -top-0.5 grid min-w-[1.15rem] place-items-center rounded-full bg-accent-500 px-1 text-[0.65rem] font-bold leading-[1.15rem] text-brand-950 ring-2 {{ $tone === 'light' ? 'ring-brand-900' : 'ring-white' }}"></span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         class="absolute left-0 top-12 z-50 w-[min(22rem,calc(100vw-1.5rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-800 shadow-xl lg:left-0">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <p class="font-display text-sm font-bold text-brand-950">Notifications</p>
            <form method="POST" action="{{ route('notifications.read-all') }}">@csrf
                <button class="text-xs font-semibold text-brand-700 hover:underline" x-show="unread > 0">Tout marquer comme lu</button>
            </form>
        </div>

        <ul class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
            <template x-for="item in items" :key="item.id">
                <li>
                    <a :href="item.url" class="flex gap-3 px-4 py-3 hover:bg-slate-50">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="item.read ? 'bg-transparent' : 'bg-brand-500'"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm" :class="item.read ? 'text-slate-700' : 'font-semibold text-brand-950'" x-text="item.title"></span>
                            <span class="mt-0.5 line-clamp-2 block text-xs text-slate-500" x-text="item.body" x-show="item.body"></span>
                            <span class="mt-1 block text-[0.7rem] text-slate-400"><span x-text="item.label"></span>, il y a <span x-text="item.ago"></span></span>
                        </span>
                    </a>
                </li>
            </template>
            <li x-show="loaded && items.length === 0" class="px-4 py-8 text-center text-sm text-slate-500">Rien pour le moment. Vous serez prévenu ici dès qu'un client vous écrit.</li>
            <li x-show="! loaded" class="px-4 py-8 text-center text-sm text-slate-400">Chargement...</li>
        </ul>

        <div class="flex items-center justify-between border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-xs">
            <a href="{{ route('notifications.index') }}" class="font-semibold text-brand-700 hover:underline">Voir tout</a>
            <a href="{{ route('notifications.preferences') }}" class="text-slate-500 hover:text-slate-800">Préférences</a>
        </div>
    </div>
</div>
