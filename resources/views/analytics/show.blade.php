<x-bot-layout :bot="$bot" tab="analytics">
    @php $max = max(1, $days->max('count')); @endphp

    <div class="space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ([
                ['Conversations (30 j)', $kpis['conversations']],
                ['Réponses appuyées sur vos sources', $kpis['answer_rate'] === null ? '–' : $kpis['answer_rate'].' %'],
                ['Transferts vers un humain', $kpis['handoffs']],
                ['Temps de réponse moyen', $kpis['latency'] ? number_format($kpis['latency'] / 1000, 1, ',', ' ').' s' : '–'],
            ] as [$label, $value])
                <div class="surface p-5">
                    <div class="text-sm text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-3xl font-semibold text-slate-900">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="surface p-5">
            <h3 class="font-medium text-slate-900">Nouvelles conversations, 14 derniers jours</h3>
            <div class="mt-4 flex h-32 items-end gap-2" role="img" aria-label="Histogramme des conversations par jour">
                @foreach ($days as $day)
                    <div class="flex flex-1 flex-col items-center gap-1">
                        <div class="w-full rounded-t bg-brand-500" style="height: {{ max(2, (int) round(100 * $day['count'] / $max)) }}%" title="{{ $day['count'] }}"></div>
                        <span class="text-[10px] text-slate-400">{{ $day['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Quand vos clients écrivent : pour être présent aux bons moments et répondre vite --}}
        <div class="surface">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="font-medium text-slate-900">Quand vos clients écrivent</h3>
                @if ($rhythm['peak'])
                    <p class="mt-1 text-sm text-slate-500">Le plus chargé : <strong class="text-slate-800">{{ mb_strtolower($rhythm['peak']['day']) }} vers {{ $rhythm['peak']['hour'] }} h</strong>. Soyez joignable à ces heures si un client demande un humain. Heures de la plateforme, 30 derniers jours.</p>
                @else
                    <p class="mt-1 text-sm text-slate-500">Les heures d'affluence apparaîtront dès que vos clients auront écrit à l'assistant.</p>
                @endif
            </div>
            @if ($rhythm['total'] > 0)
                <x-stats.heatmap :heat="$rhythm" unit="messages" />
                @if (count($rhythm['channels']) > 1)
                    <div class="flex flex-wrap gap-x-6 gap-y-1 border-t border-slate-100 px-6 py-4 text-sm text-slate-600">
                        <span class="text-slate-500">Par où ils arrivent :</span>
                        @foreach ($rhythm['channels'] as $channel => $n)
                            <span><strong class="text-slate-900">{{ $n }}</strong> {{ $channel }}</span>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        <div class="surface">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="font-medium text-slate-900">Questions restées sans réponse</h3>
                <p class="mt-1 text-sm text-slate-500">Ce que vos clients demandent et que l'assistant ne sait pas encore. Répondez ici : la réponse est mémorisée immédiatement.</p>
            </div>
            @forelse ($questions as $item)
                <div x-data="{ open: false }" class="px-6 py-4 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <div class="text-slate-900">{{ $item['question'] }}</div>
                            <div class="text-xs text-slate-500">Posée {{ $item['count'] }} fois · dernière {{ $item['last']->diffForHumans() }}</div>
                        </div>
                        <button @click="open = !open" class="shrink-0 text-sm font-medium text-brand-600 hover:text-brand-800" x-text="open ? 'Fermer' : 'Répondre'"></button>
                    </div>
                    <form x-show="open" x-cloak method="POST" action="{{ route('analytics.answer', $bot) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="question" value="{{ $item['question'] }}">
                        <textarea name="answer" rows="3" required placeholder="Écrivez la réponse exacte que l'assistant doit donner…"
                                  class="block w-full rounded-md border-slate-300 text-sm focus:border-brand-500 focus:ring-brand-500"></textarea>
                        <x-primary-button>Mémoriser la réponse</x-primary-button>
                    </form>
                </div>
            @empty
                <div class="px-6 py-10 text-center text-sm text-slate-500">Rien à signaler : aucune question sans réponse ces 30 derniers jours.</div>
            @endforelse
        </div>
    </div>
</x-bot-layout>
