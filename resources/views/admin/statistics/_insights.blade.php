@if (count($insights))
    <section aria-label="Ce qu'il faut retenir" class="grid gap-3 md:grid-cols-2">
        @foreach ($insights as $insight)
            @php
                $bar = ['good' => 'bg-emerald-500', 'warn' => 'bg-accent-500', 'info' => 'bg-brand-500'][$insight['level']] ?? 'bg-brand-500';
            @endphp
            <div class="surface relative overflow-hidden p-5 pl-6">
                <span class="absolute inset-y-0 left-0 w-1.5 {{ $bar }}" aria-hidden="true"></span>
                <h3 class="font-display text-base font-bold text-brand-950">{{ $insight['title'] }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-slate-700">{{ $insight['text'] }}</p>
                @if ($insight['tab'])
                    <a href="{{ route('admin.statistics.index', $query(['onglet' => $insight['tab']])) }}" class="mt-2 inline-block text-sm font-semibold text-brand-700 hover:underline">Voir le détail</a>
                @endif
            </div>
        @endforeach
    </section>
@endif
