<x-stats.card title="Page par page" hint="Vues, visiteurs différents, puis ce qui se passe quand on arrive ou repart par cette page.">
    @if (! count($pages))
        <p class="px-6 py-10 text-center text-sm text-slate-500">Pas encore de page vue sur cette période.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-left text-xs text-slate-500">
                        <th class="px-6 py-3 font-medium">Page</th>
                        <th class="px-3 py-3 text-right font-medium">Vues</th>
                        <th class="px-3 py-3 text-right font-medium">Visiteurs</th>
                        <th class="px-3 py-3 text-right font-medium" title="Visites qui commencent par cette page">Entrées</th>
                        <th class="px-3 py-3 text-right font-medium" title="Part des entrées qui repartent sans rien faire">Rebond</th>
                        <th class="px-3 py-3 text-right font-medium" title="Part des vues après lesquelles la visite s'arrête">Sorties</th>
                        <th class="px-3 py-3 text-right font-medium" title="Temps réellement actif sur la page">Temps</th>
                        <th class="px-6 py-3 text-right font-medium" title="Part des visiteurs qui ont lu au moins la moitié de la page">Lu à 50 %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($pages as $page)
                        <tr>
                            <td class="max-w-xs px-6 py-3"><p class="truncate font-medium text-slate-900">{{ $page['name'] }}</p>@if ($page['name'] !== $page['path']) <p class="truncate text-xs text-slate-500">{{ $page['path'] }}</p> @endif</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ \App\Support\StatsFormat::number($page['views']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ \App\Support\StatsFormat::number($page['visitors']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ \App\Support\StatsFormat::number($page['entries']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums {{ $page['entries'] >= 15 && $page['bounce_rate'] >= 75 ? 'font-semibold text-red-700' : '' }}">{{ $page['entries'] ? \App\Support\StatsFormat::percent($page['bounce_rate']) : '-' }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ \App\Support\StatsFormat::percent($page['exit_rate']) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ $page['seconds'] ? \App\Support\StatsFormat::duration($page['seconds']) : '-' }}</td>
                            <td class="px-6 py-3 text-right tabular-nums">{{ \App\Support\StatsFormat::percent($page['scroll50']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-stats.card>
