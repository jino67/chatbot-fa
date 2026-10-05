<x-bot-layout :bot="$bot" tab="sources">
    @php
        $btn = 'btn-primary';
        $tabs = [
            'file' => 'Documents',
            'image' => 'Photos',
            'url' => 'Site web',
            'facebook' => 'Facebook',
            'text' => 'Texte',
            'qa' => 'Questions / réponses',
        ];
        $busy = $sources->contains(fn ($s) => $s->isBusy());
        $focus = session('focus_source');
        $pending = $sources->filter(fn ($s) => $s->needsContent());
        $pagesLimit = $workspace->limit('pages_per_crawl');
    @endphp

    <div class="space-y-6">

        {{-- Liens Facebook / Instagram en attente de contenu : l'invitation --}}
        @foreach ($pending as $source)
            <section id="source-{{ $source->id }}" class="rounded-xl border-2 border-accent-400 bg-white p-6" @if ($focus === $source->id) x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })" @endif>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-display text-xl font-bold">Dernière étape : le contenu de votre page {{ ($source->payload['platform'] ?? 'facebook') === 'instagram' ? 'Instagram' : 'Facebook' }}</h2>
                        <p class="mt-1 max-w-2xl text-sm text-slate-600">
                            Nous avons bien enregistré votre lien (<span class="font-medium text-brand-900">{{ $source->payload['url'] ?? '' }}</span>).
                            Meta interdit aux services comme le nôtre de lire ces pages automatiquement. La page est la vôtre : vous pouvez en copier le contenu en deux minutes.
                        </p>
                    </div>
                    <x-badge tone="amber">À compléter</x-badge>
                </div>

                <div class="mt-5 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                    <ol class="space-y-3 text-sm text-slate-700">
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">1</span>
                            <span>Ouvrez votre page, puis la section <strong>« À propos »</strong> ou <strong>« Informations »</strong>.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">2</span>
                            <span>Sélectionnez le texte (<kbd class="rounded border border-slate-300 bg-slate-50 px-1 text-xs">Ctrl</kbd> + <kbd class="rounded border border-slate-300 bg-slate-50 px-1 text-xs">A</kbd>), copiez-le (<kbd class="rounded border border-slate-300 bg-slate-50 px-1 text-xs">Ctrl</kbd> + <kbd class="rounded border border-slate-300 bg-slate-50 px-1 text-xs">C</kbd>) et collez-le ci-contre.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">3</span>
                            <span>Ajoutez à la suite le texte de vos <strong>publications épinglées</strong> et de vos <strong>promotions</strong> en cours.</span></li>
                        <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">4</span>
                            <span>Si vous avez un <strong>site web</strong>, indiquez-le : nous le lisons automatiquement (jusqu'à {{ $pagesLimit }} pages avec votre offre).</span></li>
                        <li class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                            À ne pas oublier : horaires, adresse, téléphone ou WhatsApp, prix, moyens de paiement, livraison, promotions.
                        </li>
                    </ol>

                    <form method="POST" action="{{ route('sources.content', [$bot, $source]) }}" class="space-y-4">
                        @csrf @method('PUT')
                        <div>
                            <x-input-label for="fb-content-{{ $source->id }}" value="Contenu de votre page" />
                            <textarea id="fb-content-{{ $source->id }}" name="content" rows="8" required class="field" placeholder="Collez ici le texte de votre page…">{{ old('content') }}</textarea>
                        </div>
                        <div>
                            <x-input-label for="fb-site-{{ $source->id }}" value="Votre site web (facultatif)" />
                            <input id="fb-site-{{ $source->id }}" name="website" class="field" placeholder="www.monsite.com" value="{{ old('website') }}">
                        </div>
                        <button class="{{ $btn }}">Ajouter le contenu de ma page</button>
                    </form>
                </div>

                @if ($facebookConnect && ($source->payload['platform'] ?? 'facebook') === 'facebook')
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-sm">
                        <p class="text-slate-600">Vous administrez cette page ? Connectez-la : son contenu est importé et relu chaque semaine, sans copier-coller.</p>
                        <a href="{{ route('facebook.connect', $bot) }}" class="btn-outline">Connecter ma page Facebook</a>
                    </div>
                @endif
            </section>
        @endforeach

        <section class="surface" x-data="{ tab: '{{ old('type', 'file') }}' }">
            <div class="px-6 pt-5">
                <h2 class="font-display text-lg font-bold">Ajouter des connaissances</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Tout ce que vous ajoutez ici devient la mémoire de l'assistant. {{ $usage['sources']['used'] }} sur {{ $usage['sources']['limit'] }} sources utilisées.
                </p>
                <div class="mt-4 flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist">
                    @foreach ($tabs as $key => $label)
                        <button type="button" role="tab" @click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-brand-900'"
                                class="whitespace-nowrap border-b-2 px-3 pb-2 text-sm font-medium">{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div class="p-6">
                {{-- Documents --}}
                <form x-show="tab === 'file'" method="POST" action="{{ route('sources.store', $bot) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf <input type="hidden" name="type" value="file">
                    <div>
                        <x-input-label for="file" value="Fichier (PDF, Word, Excel, CSV, texte, Markdown, HTML)" />
                        <input id="file" type="file" name="file" required accept=".pdf,.docx,.txt,.md,.csv,.tsv,.xlsx,.xls,.html,.htm"
                               class="mt-1 block w-full text-sm text-slate-700 file:me-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                        <p class="mt-1 text-xs text-slate-500">20 Mo maximum. Les PDF scannés sont lus automatiquement (OCR).</p>
                        <div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                            <p><strong class="text-brand-900">Un catalogue, une carte ou une liste de prix ?</strong> Envoyez-la en Excel (.xlsx) ou en CSV : l'assistant repère les colonnes (nom, prix, disponibilité, catégorie, description), lit les prix comme vous les écrivez (« 18 000 », « 18.000 FCFA », « à partir de 5000 ») et range les produits par catégorie.</p>
                            <p class="mt-1">Les colonnes « prix d'achat », « marge » et « fournisseur » ne sont jamais lues. Un export du Commerce Manager de Meta (catalogue WhatsApp) est compris tel quel.</p>
                            <p class="mt-1"><a href="{{ route('sources.template', $bot) }}" class="font-medium text-brand-600 underline">Télécharger un modèle de catalogue</a> (s'ouvre dans Excel).</p>
                        </div>
                    </div>
                    <button class="{{ $btn }}">Envoyer le document</button>
                </form>

                {{-- Photos --}}
                <form x-show="tab === 'image'" x-cloak method="POST" action="{{ route('sources.store', $bot) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf <input type="hidden" name="type" value="image">
                    <div>
                        <x-input-label for="image" value="Photo (JPG, PNG, WebP)" />
                        <input id="image" type="file" name="image" required accept="image/jpeg,image/png,image/webp,image/gif"
                               class="mt-1 block w-full text-sm text-slate-700 file:me-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                        <p class="mt-1 text-xs text-slate-500">Affiche de prix, menu, flyer, vitrine, catalogue : le texte visible et la description de l'image sont mémorisés.</p>
                    </div>
                    <button class="{{ $btn }}">Envoyer la photo</button>
                </form>

                {{-- Site web --}}
                <form x-show="tab === 'url'" x-cloak method="POST" action="{{ route('sources.store', $bot) }}" class="space-y-4">
                    @csrf <input type="hidden" name="type" value="url">
                    <div>
                        <x-input-label for="url" value="Adresse du site ou de la page" />
                        <input id="url" name="url" required class="field" placeholder="https://www.monsite.com" value="{{ old('url') }}">
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="mode" value="Que lire ?" />
                            <select id="mode" name="mode" class="field">
                                <option value="site">Tout le site (jusqu'à {{ $pagesLimit }} pages)</option>
                                <option value="page">Cette page uniquement</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="resync" value="Mise à jour automatique" />
                            <select id="resync" name="resync" class="field">
                                <option value="never">Jamais (à la demande)</option>
                                <option value="daily">Chaque jour</option>
                                <option value="weekly">Chaque semaine</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">Le site est lu poliment, en respectant son fichier robots.txt. Les sites entièrement construits en JavaScript peuvent ne rien renvoyer : utilisez alors l'onglet Texte.</p>
                    <button class="{{ $btn }}">Lire le site</button>
                </form>

                {{-- Facebook / Instagram --}}
                <div x-show="tab === 'facebook'" x-cloak class="space-y-6">
                    <form method="POST" action="{{ route('sources.store', $bot) }}" class="space-y-4">
                        @csrf <input type="hidden" name="type" value="url"><input type="hidden" name="mode" value="page">
                        <div>
                            <x-input-label for="fb-url" value="Lien de votre page Facebook ou Instagram" />
                            <input id="fb-url" name="url" required class="field" placeholder="https://www.facebook.com/votre.page">
                            <p class="mt-1 text-xs text-slate-500">Collez le lien : nous l'enregistrons et vous guidons pour ajouter son contenu en deux minutes.</p>
                        </div>
                        <button class="{{ $btn }}">Enregistrer le lien</button>
                    </form>

                    @if ($facebookConnect)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-4 text-sm">
                            <p class="text-slate-700">Vous administrez la page ? <strong>Connectez-la</strong> : son contenu est importé et relu chaque semaine.</p>
                            <a href="{{ route('facebook.connect', $bot) }}" class="btn-outline">Connecter ma page Facebook</a>
                        </div>
                    @endif

                    <details class="rounded-lg border border-slate-200 p-4 text-sm">
                        <summary class="cursor-pointer font-medium text-brand-900">Ou collez directement le contenu de la page</summary>
                        <form method="POST" action="{{ route('sources.store', $bot) }}" class="mt-4 space-y-4">
                            @csrf <input type="hidden" name="type" value="facebook">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><x-input-label for="fb_title" value="Titre" /><input id="fb_title" name="title" required class="field" placeholder="Page Facebook de la boutique"></div>
                                <div><x-input-label for="fb_page_url" value="Lien de la page (facultatif)" /><input id="fb_page_url" type="url" name="page_url" class="field" placeholder="https://www.facebook.com/…"></div>
                            </div>
                            <div><x-input-label for="fb_content" value="Contenu de la page" /><textarea id="fb_content" name="content" rows="6" required class="field"></textarea></div>
                            <button class="{{ $btn }}">Ajouter le contenu</button>
                        </form>
                    </details>
                </div>

                {{-- Texte --}}
                <form x-show="tab === 'text'" x-cloak method="POST" action="{{ route('sources.store', $bot) }}" class="space-y-4">
                    @csrf <input type="hidden" name="type" value="text">
                    <div>
                        <x-input-label for="text_title" value="Titre" />
                        <input id="text_title" name="title" required class="field" placeholder="Ex. Horaires et adresse" value="{{ old('title') }}">
                    </div>
                    <div>
                        <x-input-label for="text_content" value="Contenu" />
                        <textarea id="text_content" name="content" rows="7" required class="field" placeholder="Collez ici vos tarifs, conditions de livraison, horaires, FAQ…">{{ old('content') }}</textarea>
                    </div>
                    <button class="{{ $btn }}">Ajouter le texte</button>
                </form>

                {{-- Questions / reponses --}}
                <form x-show="tab === 'qa'" x-cloak method="POST" action="{{ route('sources.store', $bot) }}" class="space-y-4">
                    @csrf <input type="hidden" name="type" value="qa">
                    <div>
                        <x-input-label for="qa_question" value="Question fréquente" />
                        <input id="qa_question" name="question" required class="field" placeholder="Ex. Livrez-vous à Ouagadougou ?" value="{{ old('question') }}">
                    </div>
                    <div>
                        <x-input-label for="qa_answer" value="Réponse exacte" />
                        <textarea id="qa_answer" name="answer" rows="4" required class="field">{{ old('answer') }}</textarea>
                    </div>
                    <button class="{{ $btn }}">Ajouter la réponse</button>
                </form>
            </div>
        </section>

        <section class="surface">
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <h2 class="font-display text-lg font-bold">Sources ({{ $sources->count() }})</h2>
                @if ($busy) <x-badge tone="blue">Indexation en cours…</x-badge> @endif
            </div>

            @forelse ($sources as $source)
                <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-4 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate font-semibold text-brand-950">{{ $source->name }}</span>
                            <x-badge>{{ $source->typeLabel() }}</x-badge>
                            @switch($source->status)
                                @case('ready') <x-badge tone="green">Prête</x-badge> @break
                                @case('failed') <x-badge tone="red">Échec</x-badge> @break
                                @case('processing') <x-badge tone="blue">Lecture en cours</x-badge> @break
                                @case('needs_content') <x-badge tone="amber">À compléter</x-badge> @break
                                @default <x-badge tone="amber">En attente</x-badge>
                            @endswitch
                            @if ($source->payload['connected'] ?? false) <x-badge tone="brand">Page connectée</x-badge> @endif
                        </div>
                        <div class="mt-1 text-sm text-slate-600">
                            @if ($source->status === 'processing' && isset($crawls[$source->id]))
                                @php $crawl = $crawls[$source->id]; @endphp
                                <span data-crawl="{{ $source->id }}"><strong class="text-brand-900">{{ $crawl['read'] }} page(s) lue(s)</strong> sur {{ max($crawl['found'], $crawl['read']) }} trouvée(s)<span class="text-slate-500"> : la lecture continue toute seule, vous pouvez rester sur cette page.</span></span>
                            @elseif ($source->status === 'ready' && $source->stats)
                                @if ($source->type === 'url' && isset($source->stats['found']))
                                    <strong class="text-brand-900">{{ $source->stats['pages'] }} page(s) lue(s)</strong>@if ($source->stats['found'] > $source->stats['pages']) sur {{ $source->stats['found'] }} trouvée(s)@endif
                                    @if (! empty($source->stats['products']))
                                        , <strong class="text-brand-900">{{ $source->stats['products'] }} produit(s)</strong>@if (! empty($source->stats['photos'])) dont {{ $source->stats['photos'] }} avec photo @endif
                                    @endif
                                    , {{ $source->stats['chunks'] }} extrait(s)
                                @elseif (! empty($source->stats['products']))
                                    <strong class="text-brand-900">{{ $source->stats['products'] }} produit(s) lu(s)</strong>, {{ $source->stats['chunks'] }} extrait(s)
                                @else
                                    {{ $source->stats['pages'] }} page(s), {{ $source->stats['chunks'] }} extrait(s), environ {{ number_format($source->stats['tokens'], 0, ',', ' ') }} jetons
                                @endif
                                @if ($source->last_synced_at) : mis à jour {{ $source->last_synced_at->diffForHumans() }} @endif
                            @elseif (in_array($source->type, ['url', 'facebook']) && ! empty($source->payload['url']))
                                {{ $source->payload['url'] }}
                            @endif
                        </div>
                        @if ($source->status === 'ready' && $source->type === 'url' && ! empty($source->stats['truncated']))
                            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                Votre offre lit au plus <strong>{{ $source->stats['plan_limit'] ?? $source->stats['limit'] }} pages par site</strong> : la lecture s'est arrêtée avant d'avoir tout vu. Les pages d'information (contact, livraison, FAQ), les catégories et les produits sont lus en premier. Pour lire le reste, passez à l'offre supérieure puis cliquez sur « Relire ».
                            </p>
                        @endif
                        @if ($source->status === 'ready' && $source->type === 'url' && ! empty($source->stats['ignored']))
                            <p class="mt-1 text-xs text-slate-500">{{ $source->stats['ignored'] }} page(s) sans contenu utile (panier, connexion, formulaires de commande, doublons) ont été ignorées : elles ne comptent pas dans votre limite.</p>
                        @endif
                        @if ($source->status === 'ready' && ! empty($source->stats['notes']))
                            <ul class="mt-2 space-y-1 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                @foreach ($source->stats['notes'] as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($source->status === 'failed' && $source->error)
                            <div class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $source->error }}</div>
                        @endif
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        @if (! $source->isBusy() && ! $source->needsContent() && in_array($source->type, ['url', 'file', 'image']))
                            <form method="POST" action="{{ route('sources.resync', [$bot, $source]) }}">@csrf
                                <button class="font-medium text-brand-600 hover:text-brand-800">{{ $source->status === 'failed' ? 'Réessayer' : 'Relire' }}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('sources.destroy', [$bot, $source]) }}" onsubmit="return confirm('Supprimer cette source ? L\'assistant ne s\'en servira plus.')">
                            @csrf @method('DELETE')
                            <button class="font-medium text-red-600 hover:text-red-800">Supprimer</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center text-sm text-slate-600">
                    Aucune source pour l'instant. Ajoutez un document, une photo ou le lien de votre site ci-dessus : l'assistant pourra ensuite répondre à vos clients.
                </div>
            @endforelse
        </section>
    </div>

    @if ($items->isNotEmpty())
        <section class="surface mx-auto mt-6 max-w-5xl">
            <div class="border-b border-slate-100 px-6 py-4">
                <h2 class="font-display text-lg font-bold">Produits reconnus ({{ $itemsTotal }})</h2>
                <p class="mt-1 text-sm text-slate-600">Lus sur votre site : l'assistant connaît leur prix et peut envoyer leur photo sur le chat et sur WhatsApp. Un produit mal lu se corrige sur votre site, puis « Relire ».</p>
            </div>
            <ul class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($items->take(24) as $item)
                    @php $photo = app(\App\Services\ProductImages::class)->publicUrl($item); @endphp
                    <li class="min-w-0">
                        @if ($photo)
                            <img src="{{ $photo }}" alt="{{ $item->name }}" loading="lazy" class="aspect-square w-full rounded-xl border border-slate-200 bg-slate-100 object-cover">
                        @else
                            <div class="grid aspect-square w-full place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 px-2 text-center text-xs text-slate-500">Pas de photo</div>
                        @endif
                        <p class="mt-2 truncate text-sm font-semibold text-brand-950" title="{{ $item->name }}">{{ $item->name }}</p>
                        <p class="text-xs text-slate-600">{{ $item->price_text ?: 'Prix non indiqué' }}@if ($item->availability) · {{ $item->availability }}@endif</p>
                    </li>
                @endforeach
            </ul>
            @if ($itemsTotal > 24)
                <p class="px-6 pb-5 text-xs text-slate-500">Et {{ $itemsTotal - 24 }} autre(s) produit(s).</p>
            @endif
        </section>
    @endif

    @if ($busy)
        @php $advance = $sources->filter(fn ($s) => $s->type === 'url' && $s->status === 'processing')->map(fn ($s) => ['id' => $s->id, 'url' => route('sources.advance', [$bot, $s])])->values(); @endphp
        <script>
            (function () {
                // Un site se lit par tranches de quelques secondes : cette page demande la suivante tant que la lecture n'est pas finie.
                var sites = @json($advance);
                var token = document.querySelector('meta[name=csrf-token]');
                function reload() { location.reload(); }
                if (!sites.length) { setTimeout(reload, 4000); return; }

                function next() {
                    fetch(sites[0].url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token ? token.content : '', 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.status !== 'processing') { return reload(); }
                            var el = document.querySelector('[data-crawl="' + sites[0].id + '"] strong');
                            if (el && data.progress) { el.textContent = data.progress.read + ' page(s) lue(s)'; }
                            setTimeout(next, 300);
                        })
                        .catch(function () { setTimeout(next, 4000); });
                }
                next();
            })();
        </script>
    @endif
</x-bot-layout>
