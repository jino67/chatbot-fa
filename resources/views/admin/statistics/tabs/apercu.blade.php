@include('admin.statistics._insights', ['insights' => $insights, 'query' => $query])

<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    @foreach ($kpis as $item)
        <x-stats.kpi :item="$item" />
    @endforeach
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <x-stats.card title="Visites jour après jour" hint="Une visite = une personne qui arrive sur le site, quel que soit le nombre de pages lues." class="lg:col-span-2">
        <div class="px-6 py-5"><x-stats.daily :series="$daily" key="sessions" label="visites" /></div>
    </x-stats.card>

    {{-- En direct : se rafraîchit seul toutes les 20 secondes --}}
    <section class="surface" x-data="statsLive(@js($live), @js(route('admin.statistics.live', ['public' => $audience])))" x-init="start()">
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
            <div>
                <h2 class="font-display text-lg font-bold text-brand-950">En ce moment</h2>
                <p class="mt-0.5 text-sm text-slate-600">Dans les 5 dernières minutes.</p>
            </div>
            <span class="relative flex h-3 w-3" aria-hidden="true"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span><span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500"></span></span>
        </div>
        <div class="px-6 py-5">
            <p class="font-display text-4xl font-bold text-brand-950" x-text="live.active">{{ $live['active'] }}</p>
            <p class="text-sm text-slate-600">personne(s) sur le site</p>
            <template x-if="live.pages.length">
                <ul class="mt-4 space-y-1.5 text-sm">
                    <template x-for="page in live.pages" :key="page.name">
                        <li class="flex justify-between gap-3"><span class="truncate text-slate-700" x-text="page.name"></span><span class="tabular-nums text-slate-500" x-text="page.count"></span></li>
                    </template>
                </ul>
            </template>
            <template x-if="live.recent.length">
                <div class="mt-4 border-t border-slate-100 pt-3">
                    <p class="text-xs font-medium text-slate-500">Derniers gestes</p>
                    <ul class="mt-2 space-y-1.5 text-xs text-slate-600">
                        <template x-for="(event, i) in live.recent" :key="i">
                            <li class="flex justify-between gap-3"><span class="truncate"><span x-text="event.type"></span> <span class="text-slate-500" x-text="event.name || event.page"></span></span><span class="shrink-0 text-slate-400" x-text="event.ago"></span></li>
                        </template>
                    </ul>
                </div>
            </template>
        </div>
    </section>
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <x-stats.card title="D'où viennent les visites" hint="Le canal qui amène le plus de monde, et celui dont les visiteurs agissent vraiment.">
        <x-stats.bars :rows="collect($sources)->map(fn ($s) => ['label' => $s['label'], 'value' => $s['sessions'], 'hint' => \App\Support\StatsFormat::percent($s['conversion_rate']).' aboutissent'])->all()" unit="visites" />
    </x-stats.card>

    <x-stats.card title="Les pages les plus vues">
        <x-stats.bars :rows="collect($pages)->map(fn ($p) => ['label' => $p['name'], 'value' => $p['views'], 'hint' => \App\Support\StatsFormat::duration($p['seconds']).' en moyenne'])->all()" unit="vues" />
    </x-stats.card>
</div>

@if ($heat['peak'])
    <x-stats.card title="Quand viennent-ils ?" hint="Le plus chargé : {{ mb_strtolower($heat['peak']['day']) }} vers {{ $heat['peak']['hour'] }} h ({{ $heat['peak']['count'] }} visites sur ce créneau). Détail dans l'onglet Affluence.">
        <x-stats.heatmap :heat="$heat" />
    </x-stats.card>
@endif

@once
    <script>
        function statsLive(initial, url) {
            return {
                live: initial,
                start() {
                    setInterval(() => {
                        if (document.hidden) return;
                        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                            .then((r) => r.ok ? r.json() : null).then((data) => { if (data) this.live = data; }).catch(() => {});
                    }, 20000);
                },
            };
        }
    </script>
@endonce
