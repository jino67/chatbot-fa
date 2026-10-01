@php
    $tones = ['good' => ['Bon', 'bg-emerald-50 text-emerald-800 ring-emerald-600/20'], 'needs' => ['À améliorer', 'bg-accent-50 text-accent-700 ring-accent-500/40'], 'poor' => ['Mauvais', 'bg-red-50 text-red-700 ring-red-600/20'], 'none' => ['En attente', 'bg-slate-100 text-slate-600 ring-slate-500/20']];
    $scrollRows = collect($scroll)->map(fn ($s) => ['label' => $s['label'], 'value' => $s['sessions'], 'hint' => \App\Support\StatsFormat::percent($s['percent']).' des visites'])->values()->all();
@endphp

<x-stats.card title="Vitesse ressentie" hint="Pour trois visiteurs sur quatre, la page est au moins aussi rapide que la valeur indiquée. Les seuils sont ceux publiés par Google.">
    <div class="grid gap-px bg-slate-100 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($vitals as $v)
            <div class="bg-white p-5">
                <p class="text-xs text-slate-500">{{ $v['label'] }}</p>
                <p class="mt-2 font-display text-2xl font-bold text-brand-950">{{ $v['display'] }}</p>
                <span class="mt-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $tones[$v['rating']][1] }}">{{ $tones[$v['rating']][0] }}</span>
                <p class="mt-1 text-xs text-slate-400">{{ $v['samples'] }} mesure(s)</p>
            </div>
        @endforeach
    </div>
</x-stats.card>

<div class="grid gap-6 lg:grid-cols-2">
    <x-stats.card title="Jusqu'où ils lisent" hint="Part des visites qui ont fait défiler la page jusqu'à ce niveau.">
        <x-stats.bars :rows="$scrollRows" unit="visites" />
    </x-stats.card>

    <x-stats.card title="Formulaires" hint="Commencés, envoyés, et part de ceux qu'on abandonne en route.">
        @if (! count($forms))
            <p class="px-6 py-8 text-center text-sm text-slate-500">Aucun formulaire commencé sur cette période.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($forms as $form)
                    <li class="flex items-center justify-between gap-3 px-6 py-3 text-sm">
                        <span class="min-w-0 truncate text-slate-800">{{ $form['name'] }}</span>
                        <span class="shrink-0 tabular-nums text-slate-600">{{ $form['starts'] }} commencés, {{ $form['submits'] }} envoyés <span class="{{ $form['abandon'] >= 50 ? 'font-semibold text-red-700' : 'text-slate-500' }}">({{ \App\Support\StatsFormat::percent($form['abandon']) }} abandon)</span></span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-stats.card>

    <x-stats.card title="Erreurs dans le navigateur" hint="Messages d'erreur JavaScript rencontrés par les visiteurs. À transmettre à l'équipe technique." class="lg:col-span-2">
        @if (! count($jsErrors))
            <p class="px-6 py-8 text-center text-sm text-slate-500">Aucune erreur signalée. C'est bon signe.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($jsErrors as $error)
                    <li class="px-6 py-3 text-sm">
                        <p class="break-words font-medium text-slate-900">{{ $error['name'] }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $error['count'] }} fois, {{ $error['visitors'] }} visiteur(s), page {{ \App\Services\Analytics\Stats::pageName($error['path']) }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-stats.card>

    <x-stats.card title="Clics répétés" hint="Trois clics ou plus au même endroit en moins de deux secondes." class="lg:col-span-2">
        <x-stats.bars :rows="collect($rage)->map(fn ($r) => ['label' => $r['name'], 'value' => $r['clicks'], 'hint' => $r['page']])->all()" unit="fois" empty="Aucun clic répété sur cette période." />
    </x-stats.card>
</div>
