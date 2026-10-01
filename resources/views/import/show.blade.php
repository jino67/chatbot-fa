<x-bot-layout :bot="$bot" tab="import">
    @php
        $currency = \App\Support\Currency::current();
        $price = $addon ? \App\Support\Currency::formatPrices($addon['prices'])[$currency] : null;
    @endphp

    <div class="mx-auto max-w-4xl space-y-6">
        <section class="surface flex flex-wrap items-start gap-4 p-6">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-brand-50"><x-illus name="chat" class="h-8 w-8" /></span>
            <div class="min-w-0 flex-1">
                <h2 class="font-display text-lg font-bold">Apprendre de vos vraies conversations WhatsApp</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Envoyez l'export d'une discussion : {{ $bot->name }} apprend <strong>votre façon d'écrire</strong> (ton, longueur, formules) et retient <strong>ce que vous répondez d'habitude</strong> à vos clients.
                    Vous validez tout avant l'enregistrement, et rien n'est gardé sans votre accord.
                </p>
            </div>
        </section>

        @unless ($allowed)
            <section class="surface space-y-4 p-6">
                <h3 class="font-display text-base font-bold">{{ $addon['name'] }}</h3>
                <p class="text-sm text-slate-600">{{ $addon['description'] }} Cette option est incluse dans les offres Pro et Business. Pour votre offre actuelle, vous pouvez l'ajouter à la carte : <strong class="text-brand-950">{{ $price }}</strong>, une seule fois.</p>
                @if ($pending)
                    <p class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900">Demande en cours : payez l'option selon les indications de la page <a href="{{ route('billing.show') }}" class="font-semibold underline">Abonnement</a>, elle est activée dès réception.</p>
                @else
                    <form method="POST" action="{{ route('import.option') }}">
                        @csrf
                        <button class="btn-primary">Demander cette option</button>
                    </form>
                @endif
            </section>
        @else
            {{-- Style déjà appris --}}
            @if ($imported && ($imported['style'] ?? '') !== '')
                <section class="surface space-y-4 p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-display text-base font-bold">Ce que {{ $bot->name }} a appris</h3>
                        <x-badge :tone="($imported['enabled'] ?? false) ? 'green' : 'gray'">{{ ($imported['enabled'] ?? false) ? 'Style actif' : 'Style désactivé' }}</x-badge>
                    </div>
                    <p class="whitespace-pre-line rounded-xl bg-slate-50 p-4 text-sm text-slate-700">{{ $imported['style'] }}</p>
                    <p class="text-xs text-slate-500">
                        {{ (int) ($imported['pairs'] ?? 0) }} réponse(s) habituelle(s) retenue(s), import du {{ \Illuminate\Support\Carbon::parse($imported['at'] ?? now())->translatedFormat('j F Y') }}.
                        Les réponses habituelles sont dans la source « Réponses habituelles (import WhatsApp) », dans l'onglet Connaissances.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('import.style', $bot) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="enabled" value="{{ ($imported['enabled'] ?? false) ? 0 : 1 }}">
                            <button class="btn-outline">{{ ($imported['enabled'] ?? false) ? 'Désactiver le style' : 'Activer le style' }}</button>
                        </form>
                        <form method="POST" action="{{ route('import.style', $bot) }}" onsubmit="return confirm('Effacer le style appris ?')">
                            @csrf @method('PUT')
                            <input type="hidden" name="action" value="delete">
                            <button class="btn-outline text-red-700">Effacer le style</button>
                        </form>
                    </div>
                </section>
            @endif

            {{-- Étape 1 : le fichier --}}
            @if ($step === 'upload')
                <section class="surface space-y-5 p-6">
                    <h3 class="font-display text-base font-bold">1. Exportez une discussion depuis WhatsApp</h3>
                    <ol class="list-decimal space-y-1.5 ps-5 text-sm text-slate-700">
                        <li>Ouvrez, dans WhatsApp, une discussion où vous répondez à vos clients (ou un groupe).</li>
                        <li><strong>Android</strong> : menu ⋮, « Plus », « Exporter la discussion », <strong>« Sans médias »</strong>. <strong>iPhone</strong> : touchez le nom en haut, « Exporter la discussion », « Sans médias ».</li>
                        <li>Enregistrez le fichier (.txt ou .zip) et envoyez-le ici. Vous pouvez recommencer avec d'autres discussions.</li>
                    </ol>

                    <form method="POST" action="{{ route('import.upload', $bot) }}" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="export" value="Fichier exporté (.txt ou .zip, 10 Mo au plus)" />
                            <input id="export" name="export" type="file" accept=".txt,.zip,text/plain,application/zip" class="mt-1 block w-full text-sm file:me-4 file:rounded-full file:border-0 file:bg-brand-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-brand-700" required>
                            <x-input-error :messages="$errors->get('export')" class="mt-1" />
                        </div>

                        <div class="rounded-xl bg-slate-50 p-4 text-sm text-slate-700">
                            <p class="font-semibold text-brand-950">Vos clients et leurs données</p>
                            <ul class="mt-2 list-disc space-y-1 ps-5">
                                <li>Le fichier est lu puis <strong>jeté</strong> : il n'est jamais conservé.</li>
                                <li>Les numéros de téléphone, adresses e-mail, suites de chiffres et noms de vos clients sont <strong>retirés avant tout enregistrement</strong>.</li>
                                <li>Pendant que vous validez (30 minutes au plus), seule une version déjà anonymisée est gardée, puis effacée.</li>
                            </ul>
                        </div>

                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" name="consent" value="1" class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500" required>
                            <span>Je confirme que je suis autorisé à utiliser ces conversations, et que j'ai informé mes clients que leurs échanges avec {{ $bot->company() }} peuvent servir à améliorer son assistant.</span>
                        </label>
                        <x-input-error :messages="$errors->get('consent')" class="mt-1" />

                        <button class="btn-primary">Lire la discussion</button>
                    </form>
                </section>
            @endif

            {{-- Étape 2 : qui êtes-vous ? --}}
            @if ($step === 'owner')
                <section class="surface space-y-5 p-6">
                    <h3 class="font-display text-base font-bold">2. Qui êtes-vous dans cette discussion ?</h3>
                    <p class="text-sm text-slate-600">{{ count($prepared['messages']) }} messages lus. Choisissez la personne qui répond aux clients : c'est son style que l'assistant apprendra.</p>
                    <form method="POST" action="{{ route('import.analyze', $bot) }}" class="space-y-3">
                        @csrf
                        @foreach ($prepared['authors'] as $alias => $author)
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:border-brand-300">
                                <input type="radio" name="owner" value="{{ $alias }}" class="mt-1 border-slate-300 text-brand-600 focus:ring-brand-500" @checked($loop->first) required>
                                <span class="min-w-0">
                                    <span class="block font-semibold text-brand-950">{{ $author['name'] }} <span class="font-normal text-slate-500">: {{ $author['count'] }} message{{ $author['count'] > 1 ? 's' : '' }}</span></span>
                                    @foreach ($author['samples'] as $sample)
                                        <span class="mt-1 block truncate text-sm text-slate-500">« {{ $sample }} »</span>
                                    @endforeach
                                </span>
                            </label>
                        @endforeach
                        <x-input-error :messages="$errors->get('owner')" class="mt-1" />
                        <div class="flex flex-wrap items-center gap-3">
                            <button class="btn-primary">Analyser</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('import.cancel', $bot) }}">@csrf @method('DELETE')<button class="text-sm text-slate-500 underline hover:text-brand-700">Annuler et tout effacer</button></form>
                </section>
            @endif

            {{-- Étape 3 : validation --}}
            @if ($step === 'review')
                <form method="POST" action="{{ route('import.commit', $bot) }}" class="space-y-6">
                    @csrf
                    <section class="surface space-y-4 p-6">
                        <h3 class="font-display text-base font-bold">3. Votre façon d'écrire</h3>
                        <p class="text-sm text-slate-600">
                            Analyse de {{ $analysis['owner_messages'] }} de vos messages{{ $analysis['llm'] ? ', résumée par le modèle' : '' }}. Corrigez le texte si besoin : c'est lui que l'assistant suivra.
                        </p>
                        <textarea name="style" rows="7" class="field" maxlength="1500">{{ old('style', $analysis['style']) }}</textarea>
                        @if ($analysis['examples'])
                            <details class="text-sm text-slate-600">
                                <summary class="cursor-pointer font-medium text-brand-700">Exemples de vos réponses qui serviront de modèle</summary>
                                <ul class="mt-2 list-disc space-y-1 ps-5">
                                    @foreach ($analysis['examples'] as $example)<li>{{ $example }}</li>@endforeach
                                </ul>
                            </details>
                        @endif
                        <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="use_style" value="1" checked class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> Utiliser ce style pour les réponses de l'assistant</label>
                    </section>

                    <section class="surface space-y-4 p-6" x-data="{ all: true }">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-display text-base font-bold">Ce que vous répondez d'habitude ({{ count($analysis['pairs']) }})</h3>
                            @if ($analysis['pairs'])
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" checked @change="$root.querySelectorAll('input[data-keep]').forEach(i => i.checked = $event.target.checked)" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"> Tout garder</label>
                            @endif
                        </div>
                        <p class="text-sm text-slate-600">Ces questions et réponses ont été anonymisées. Décochez celles qui ne doivent pas servir (prix périmés, cas particuliers) et corrigez les réponses : elles complètent la base de connaissances de l'assistant.</p>

                        @forelse ($analysis['pairs'] as $i => $pair)
                            <div class="rounded-xl border border-slate-200 p-4">
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" data-keep name="pairs[{{ $i }}][keep]" value="1" checked class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <span class="min-w-0 flex-1 space-y-2">
                                        <span class="block text-xs font-medium text-slate-500">Question du client</span>
                                        <input name="pairs[{{ $i }}][q]" class="field" maxlength="300" value="{{ $pair['q'] }}">
                                        <span class="block text-xs font-medium text-slate-500">Votre réponse</span>
                                        <textarea name="pairs[{{ $i }}][a]" rows="2" class="field" maxlength="900">{{ $pair['a'] }}</textarea>
                                    </span>
                                </label>
                            </div>
                        @empty
                            <p class="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Aucune paire question / réponse exploitable n'a été trouvée : seul votre style sera appris.</p>
                        @endforelse
                    </section>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <button class="btn-primary px-6 py-3">Enregistrer</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('import.cancel', $bot) }}">@csrf @method('DELETE')<button class="text-sm text-slate-500 underline hover:text-brand-700">Annuler et tout effacer</button></form>
            @endif
        @endunless
    </div>
</x-bot-layout>
