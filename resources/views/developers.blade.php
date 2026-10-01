@php
    $name = $brand['name'];
    $description = "Appelez l'assistant de votre entreprise depuis votre propre application : une requête, une réponse sourcée, mêmes documents et mêmes garde-fous que sur votre site.";
    $curl = "curl {$endpoint} \\\n  -H \"Authorization: Bearer kma_votre_cle\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"message\": \"Vous livrez à Bobo ?\", \"conversation_id\": \"client-4821\"}'";
    $js = "const res = await fetch('{$endpoint}', {\n  method: 'POST',\n  headers: {\n    Authorization: 'Bearer kma_votre_cle',\n    'Content-Type': 'application/json',\n  },\n  body: JSON.stringify({ message: 'Vous livrez à Bobo ?', conversation_id: 'client-4821' }),\n});\nconst { answer, sources, usage } = await res.json();";
    $response = "{\n  \"conversation_id\": \"client-4821\",\n  \"answer\": \"Oui, à Bobo-Dioulasso : 2 000 FCFA, livré sous 48 h.\",\n  \"grounded\": true,\n  \"handoff\": false,\n  \"suggestions\": [\"Commander\", \"Autres villes\"],\n  \"sources\": [{ \"title\": \"Livraison\", \"url\": \"https://boutique.example/livraison\" }],\n  \"usage\": { \"answers_used\": 128, \"answers_limit\": 3000 }\n}";
    $errors = [
        ['401', 'missing_key, invalid_key', "La clé manque, est inconnue ou a été révoquée."],
        ['403', 'plan_required, account_suspended', "L'offre n'inclut pas l'API, ou l'espace est suspendu."],
        ['422', 'validation', 'Le message est vide ou trop long (2 000 caractères au plus).'],
        ['429', 'quota_exceeded', "Le quota de réponses du mois est atteint. Le champ usage donne la date de remise à zéro."],
        ['429', 'trop de requêtes', '60 requêtes par minute et par clé : réessayez après quelques secondes.'],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo :title="'API pour développeurs : appeler votre assistant | '.$name" :description="$description" :canonical="route('developers')" />
    <x-pwa-meta />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    <script>try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches&&document.referrer&&new URL(document.referrer).origin===location.origin){document.documentElement.classList.add('page-arriving')}}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white">

<x-site-nav :name="$name" :landing="false" />

<main>
    {{-- Heros : le code d'abord --}}
    <section class="wax relative overflow-hidden text-white" data-ripple-field>
        <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 pb-28 pt-28 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:pb-36 lg:pt-36">
            <div>
                <h1 class="font-display text-[1.85rem] font-bold leading-[1.12] sm:text-[2.5rem] lg:text-[2.75rem]">Votre assistant, dans votre application.</h1>
                <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/90">Une requête, une réponse sourcée. Mêmes documents, même consigne et mêmes garde-fous que sur votre site : appelez-le depuis votre appli mobile, votre back-office ou votre serveur.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a data-magnetic href="{{ route('register') }}" class="btn-accent px-7 py-3.5 text-base">Obtenir ma clé d'API</a>
                    <a href="#exemple" class="btn border border-white/40 px-7 py-3.5 text-base text-white hover:bg-white/10">Voir un exemple</a>
                </div>
                <ul class="mt-8 space-y-2 text-sm text-white/85">
                    @foreach (['Une clé secrète par assistant, révocable en un clic', 'Chaque réponse cite ses sources et dit quand elle ne sait pas', 'Quota et consommation visibles en direct'] as $point)
                        <li class="flex items-start gap-2.5"><span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-feuille-500 text-white"><x-icon name="check" class="h-3 w-3" /></span>{{ $point }}</li>
                    @endforeach
                </ul>
            </div>

            <div x-data="{ tab: 'curl' }" class="overflow-hidden rounded-2xl rounded-bl-md bg-brand-950 shadow-pop ring-1 ring-white/15">
                <div class="flex items-center gap-1 border-b border-white/10 px-3 py-2">
                    @foreach (['curl' => 'curl', 'js' => 'JavaScript'] as $key => $label)
                        <button type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'bg-white/15 text-white' : 'text-white/60 hover:text-white'" class="rounded-full px-3 py-1 text-xs font-semibold transition">{{ $label }}</button>
                    @endforeach
                    <span class="ms-auto rounded-full bg-feuille-500/20 px-2.5 py-0.5 text-[11px] font-semibold text-feuille-400">POST /api/v1/chat</span>
                </div>
                <pre x-show="tab === 'curl'" class="overflow-x-auto p-4 text-[12.5px] leading-relaxed text-sky-100"><code>{{ $curl }}</code></pre>
                <pre x-show="tab === 'js'" x-cloak class="overflow-x-auto p-4 text-[12.5px] leading-relaxed text-sky-100"><code>{{ $js }}</code></pre>
            </div>
        </div>
        <x-wave fill="#ffffff" />
    </section>

    {{-- Trois etapes --}}
    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
        <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Trois étapes, dix minutes.</h2>
        <ol class="mt-10 grid gap-8 md:grid-cols-3">
            @foreach ([
                ['Créez l\'assistant', 'Ajoutez vos documents, votre site ou vos photos, comme pour un assistant classique. Testez-le dans la zone de test.', 'doc'],
                ['Générez une clé', 'Dans votre espace, page « Développeurs » : une clé par assistant, montrée une seule fois, révocable à tout moment.', 'shield'],
                ['Appelez l\'API', 'Envoyez un message, recevez la réponse, ses sources et votre consommation. Gardez le même conversation_id pour suivre un fil.', 'chat'],
            ] as $i => [$title, $text, $icon])
                <li data-tilt class="surface-link group p-6">
                    <x-illus :name="$icon" class="h-12 w-12" />
                    <h3 class="mt-4 text-lg font-bold">{{ $title }}</h3>
                    <p class="mt-2 text-slate-600">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Reponse et erreurs --}}
    <section id="exemple" class="relative scroll-mt-20 bg-mist">
        <x-wave fill="#ffffff" position="top" size="sm" :layers="1" />
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-20 sm:px-6 lg:grid-cols-2 lg:py-28">
            <div>
                <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Une réponse qui se vérifie.</h2>
                <p class="mt-4 text-lg text-slate-600">L'assistant répond d'après vos documents et le dit : <code class="rounded bg-white px-1.5 py-0.5 text-sm">grounded</code> vaut <code class="rounded bg-white px-1.5 py-0.5 text-sm">false</code> quand il n'a pas trouvé l'information, et <code class="rounded bg-white px-1.5 py-0.5 text-sm">handoff</code> passe à <code class="rounded bg-white px-1.5 py-0.5 text-sm">true</code> quand un humain doit reprendre.</p>
                <pre class="mt-6 overflow-x-auto rounded-2xl rounded-bl-md bg-brand-950 p-4 text-[12.5px] leading-relaxed text-sky-100"><code>{{ $response }}</code></pre>
            </div>
            <div>
                <h3 class="font-display text-lg font-bold">Erreurs et limites</h3>
                <div class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-2xl rounded-bl-md border border-slate-200 bg-white">
                    @foreach ($errors as [$status, $code, $text])
                        <div class="flex gap-3 px-4 py-3 text-sm">
                            <span class="w-9 shrink-0 font-display font-bold text-brand-700">{{ $status }}</span>
                            <div><p class="font-mono text-[12px] text-slate-500">{{ $code }}</p><p class="text-slate-700">{{ $text }}</p></div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-sm text-slate-600">Quelle que soit l'erreur, la réponse est un objet <code class="rounded bg-white px-1 text-[12px]">{ "error": { "code", "message" } }</code>.</p>
            </div>
        </div>
        <x-wave fill="#ffffff" size="sm" :layers="1" />
    </section>

    {{-- Offre --}}
    <section id="tarifs" class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
        <div class="max-w-2xl">
            <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Une offre simple, dans votre monnaie.</h2>
            <p class="mt-4 text-lg text-slate-600">Vous payez un forfait mensuel, avec un volume de réponses inclus. Au-delà, écrivez-nous : nous adaptons l'offre à votre usage.</p>
            <x-currency-switcher class="mt-6" />
        </div>

        <div data-drop-in class="relative mt-10 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($plans as $plan)
                <div data-tilt class="group surface-link flex flex-col p-6">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-50"><x-illus name="chip" class="h-7 w-7" /></span>
                        <h3 class="font-display text-lg font-bold">{{ $plan->name }}</h3>
                    </div>
                    <p class="mt-2 min-h-[2.5rem] text-sm text-slate-600">{{ $plan->tagline }}</p>
                    <p class="mt-5 font-display">
                        <span class="inline-block text-[1.65rem] font-bold leading-none" data-prices="{{ json_encode($plan->formattedPrices(), JSON_UNESCAPED_UNICODE) }}">{{ $plan->formattedPrice() }}</span>
                        <span class="mt-1.5 block font-sans text-sm font-normal text-slate-500">{{ $plan->periodLabel() }}</span>
                    </p>
                    <ul class="mt-5 flex-1 space-y-2.5 text-sm text-slate-700">
                        <li>{{ number_format($plan->limit('messages_per_month'), 0, ',', "\u{202F}") }} réponses par mois via l'API</li>
                        <li>{{ $plan->limit('bots') }} assistants, une clé chacun</li>
                        <li>{{ $plan->limit('sources') }} sources de connaissances</li>
                        <li>{{ $plan->limit('members') }} utilisateurs</li>
                        <li>Consommation en direct, clés révocables</li>
                        <li class="opacity-50 line-through">WhatsApp et widget de chat</li>
                    </ul>
                    <a href="{{ route('register') }}" class="btn-primary mt-6">Choisir {{ $plan->name }}</a>
                </div>
            @endforeach
        </div>
    </section>
</main>

<x-site-footer :name="$name" :aliases="[]" :wa="null" :landing="false" />
</body>
</html>
