@if (! $chat['available'])
    <div class="surface px-6 py-12 text-center text-sm text-slate-600">
        L'assistant de la page d'accueil n'est pas encore choisi.
        <a href="{{ route('admin.settings.edit') }}" class="font-semibold text-brand-700 underline">Le sélectionner dans les paramètres</a>.
    </div>
@else
    @php
        $hours = collect(range(0, 23))->map(fn ($h) => ['label' => sprintf('%02d h', $h), 'value' => $chat['by_hour'][$h]])->all();
    @endphp
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div class="surface p-5"><p class="text-sm text-slate-600">Conversations</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::number($chat['conversations']) }}</p></div>
        <div class="surface p-5"><p class="text-sm text-slate-600">Questions posées</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::number($chat['questions']) }}</p></div>
        <div class="surface p-5"><p class="text-sm text-slate-600">Questions par conversation</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::decimal($chat['per_conversation']) }}</p></div>
        <div class="surface p-5"><p class="text-sm text-slate-600">Passées à un humain</p><p class="mt-2 font-display text-3xl font-bold text-brand-950">{{ \App\Support\StatsFormat::number($chat['handoffs']) }}</p></div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-stats.card title="À quelle heure ils posent leurs questions"><x-stats.bars :rows="$hours" unit="questions" /></x-stats.card>
        <x-stats.card title="Les dernières questions" hint="Ce que veulent savoir les visiteurs : c'est le meilleur guide pour compléter la page d'accueil et les offres.">
            @if (! count($chat['recent']))
                <p class="px-6 py-8 text-center text-sm text-slate-500">Aucune question sur cette période.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($chat['recent'] as $question)
                        <li class="flex items-start justify-between gap-3 px-6 py-3 text-sm"><span class="break-words text-slate-800">{{ $question['text'] }}</span><span class="shrink-0 text-xs text-slate-400">{{ $question['at'] }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-stats.card>
    </div>
@endif
