@php
    $name = $brand['name'];
    $wa = $brand['whatsapp'] ? 'https://wa.me/'.$brand['whatsapp'].'?text='.rawurlencode("Bonjour, je voudrais en savoir plus sur {$name}.") : null;
    $description = "Chatbot WhatsApp et assistant virtuel pour PME : il lit vos documents, répond à vos clients 24 h sur 24 sur WhatsApp et sur votre site, puis vous passe la main.";
    $featured = collect($sectors)->only(['commerce', 'restaurant', 'sante', 'education', 'immobilier', 'beaute', 'hotellerie', 'services']);
    $trial = $plans->first(fn ($p) => $p->hasTrial());
    $freeLine = $trial ? "Essai gratuit de {$trial->trial_days} jours, sans carte bancaire." : 'Gratuit pour démarrer, sans carte bancaire.';

    // Autres graphies du nom (kuma, couma...) : reprises seulement si le nom de la marque est celui d'origine.
    $aliases = strcasecmp($name, config('brand.name')) === 0 ? config('brand.aliases', []) : [];
    $aliasList = $aliases ? implode(', ', array_slice($aliases, 0, -1)).' ou '.end($aliases) : '';

    $faq = [
        ['Mon assistant peut-il inventer un prix ou un horaire ?', "Il est construit pour ne pas le faire : il ne répond qu'à partir de vos documents et de vos textes. Quand l'information manque, il le dit et propose de contacter votre équipe. Vous voyez ensuite la liste des questions restées sans réponse et vous y répondez en une minute."],
        ['Faut-il avoir un site web ?', "Non. Vous pouvez donner vos documents, vos photos d'affiches ou de menus, ou coller du texte. Votre assistant vit sur votre site si vous en avez un, sur WhatsApp, ou sur une page de démonstration que vous partagez par lien."],
        ['Comment se passe WhatsApp ?', "Vous nous indiquez le numéro à utiliser. Notre équipe technique s'occupe de la configuration avec vous : compte WhatsApp Business, vérification, branchement. Vous gardez la propriété de votre numéro et vous suivez les conversations depuis votre tableau de bord. Les messages WhatsApp sont inclus dans votre offre : vous n'avez aucun compte à ouvrir ni aucun frais à payer à WhatsApp."],
        ['Et si un client veut parler à une personne ?', "Il le dit, et l'assistant vous passe la main : vous êtes prévenu par e-mail, la conversation apparaît dans votre boîte de réception et vous répondez au client sur le même canal, sur le site ou sur WhatsApp."],
        ['Mes documents servent-ils à d\'autres entreprises ?', "Non. Chaque entreprise a son espace isolé. Vos documents ne servent qu'à votre assistant, et vous pouvez les supprimer à tout moment."],
        ['Quelles langues comprend-il ?', "Il répond en français, en anglais et en arabe, dans la langue de votre client. Les langues locales (mooré, dioula, shikomori) ne sont pas garanties : testez avec vos propres phrases avant de vous engager."],
        ['Comment payer ?', "Par Orange Money, Moov Money, Coris Money ou virement. Vous nous envoyez la référence du paiement et votre offre est activée. Vous pouvez changer d'offre ou arrêter à tout moment."],
    ];
    if ($aliases) {
        array_splice($faq, 6, 0, [["Comment s'écrit {$name} ?", "{$name} vient de « kuma », la parole en bambara et en dioula. On le trouve aussi écrit {$aliasList} : c'est le même service, l'assistant qui répond à vos clients sur votre site et sur WhatsApp."]]);
    }
    $seo = app(\App\Support\SeoPages::class);
    $currency = \App\Support\Currency::current();
    $graph = [
        array_filter([
            '@type' => 'SoftwareApplication',
            'name' => $name,
            'alternateName' => $aliases ?: null,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => $description,
            'url' => url('/'),
            'inLanguage' => 'fr',
            'offers' => $plans->map(fn ($p) => ['@type' => 'Offer', 'name' => $p->name, 'price' => (string) ($p->priceIn($p->currencyFor($currency)) ?? 0), 'priceCurrency' => $p->currencyFor($currency)])->values(),
        ], fn ($v) => $v !== null),
        // Nom du site : les moteurs de recherche affichent ce nom et retrouvent ses autres graphies.
        array_filter(['@type' => 'WebSite', 'name' => $name, 'alternateName' => $aliases ?: null, 'url' => url('/'), 'inLanguage' => 'fr'], fn ($v) => $v !== null),
        $seo->organization(),
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($item) => ['@type' => 'Question', 'name' => $item[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]]], $faq)],
    ];
    $sectorPages = collect(\App\Support\SeoPages::all())->where('type', 'sector')->mapWithKeys(fn ($p) => [$p['sector'] => route('seo.'.$p['key'])]);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo :title="$name.' : chatbot WhatsApp et assistant virtuel pour votre entreprise'" :description="$description" :canonical="url('/')" :graph="$graph"
           :keywords="$aliases ? strtolower($name).', '.strtolower(implode(', ', $aliases)).', chatbot whatsapp, assistant virtuel, réponse automatique clients, chatbot pme afrique' : null" />
    <x-pwa-meta />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=unbounded:500,600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    <script>try{var d=document.documentElement,t=+localStorage.getItem('kouma-intro')||0,q=matchMedia('(prefers-reduced-motion: reduce)').matches;if(!q&&(Date.now()-t>900000||/[?&]intro\b/.test(location.search))){d.classList.add('intro-pending')}else if(!q&&document.referrer&&new URL(document.referrer).origin===location.origin){d.classList.add('page-arriving')}}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white" data-intro>

<x-site-nav :name="$name" />

<main>
{{-- ================================ Heros ================================ --}}
<section data-track-view="accueil" class="wax relative overflow-x-clip text-white" data-parallax data-ripple-field>
    <div class="relative mx-auto grid max-w-6xl items-center gap-14 px-4 pb-28 pt-28 sm:px-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:pb-36 lg:pt-36">
        <div>
            <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3.5 py-1.5 text-sm font-medium text-white ring-1 ring-inset ring-white/25">
                <svg viewBox="0 0 24 24" class="h-4 w-4 text-[#25D366]" fill="currentColor" aria-hidden="true"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.39-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.21 3.07.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35M12.05 21.78h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88M20.46 3.49A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.9 0-3.18-1.24-6.17-3.48-8.41"/></svg>
                Chatbot WhatsApp pour entreprises
            </p>
            <h1 class="mt-5 font-display text-[1.85rem] font-bold leading-[1.12] sm:text-[2.5rem] lg:text-[2.75rem]">
                Vos clients vous écrivent sur WhatsApp. Votre assistant leur répond, même la nuit.
            </h1>
            <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/90">
                {{ $name }} lit vos tarifs, vos affiches et votre site, puis répond à votre place sur WhatsApp : prix, horaires, livraison, disponibilité. Il peut aussi vivre sur votre site web. Quand une question dépasse le robot, il vous passe la main.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="{{ route('register') }}" class="btn-accent px-7 py-3.5 text-base">Créer mon assistant WhatsApp gratuitement</a>
                @if ($landingBotKey)
                    <button type="button" onclick="window.KoumaWidget && window.KoumaWidget.open()" class="btn border border-white/40 px-7 py-3.5 text-base text-white hover:bg-white/10">Essayer une démonstration</button>
                @else
                    <a href="#fonctionnement" class="btn border border-white/40 px-7 py-3.5 text-base text-white hover:bg-white/10">Voir comment ça marche</a>
                @endif
            </div>

            <p class="mt-5 max-w-lg text-sm text-white/80">
                {{ $freeLine }} Ensuite, paiement par Orange Money, Moov Money ou virement.
                @if ($wa)
                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="ms-1 font-medium text-accent-300 underline underline-offset-4 hover:text-accent-200">Parler à un conseiller sur WhatsApp</a>
                @endif
            </p>
        </div>

        <div class="relative">
            {{-- Formes autour du telephone (jamais derriere le texte) : elles flottent et suivent le pointeur. --}}
            <div class="pointer-events-none absolute inset-0" aria-hidden="true" data-scroll-parallax="-70">
                <svg viewBox="0 0 100 50" class="drift parallax absolute -right-6 -top-10 w-44 sm:-right-14 sm:w-60" style="--dx: 12px; --dy: -14px; --t: 11s; --depth: 24px; --r0: -4deg; --r1: 5deg"><path d="M0 50a50 50 0 0 1 100 0Z" fill="#FFB400"/></svg>
                <svg viewBox="0 0 100 50" class="drift parallax absolute -left-4 bottom-20 w-24 sm:-left-12 sm:w-32" style="--dx: -8px; --dy: 12px; --t: 9s; --depth: -16px; --r0: 8deg; --r1: -6deg"><path d="M0 0a50 50 0 0 0 100 0Z" fill="#E8336D"/></svg>
                <svg viewBox="0 0 100 100" class="drift parallax absolute -bottom-8 right-8 w-16 sm:w-20" style="--dx: 8px; --dy: 10px; --t: 13s; --depth: 14px; --r0: 0deg; --r1: 14deg"><path d="M0 0h100a100 100 0 0 1-100 100Z" fill="#12A574"/></svg>
                <svg viewBox="0 0 100 50" class="drift parallax absolute -left-2 top-4 w-20 sm:-left-10 sm:w-28" style="--dx: 10px; --dy: -8px; --t: 15s; --depth: 20px; --r0: 3deg; --r1: -5deg"><path d="M0 50a50 50 0 0 1 100 0Z" fill="none" stroke="#fff" stroke-opacity=".6" stroke-width="3"/></svg>
            </div>
            <x-fold-demo class="relative z-10" />
            <p class="relative z-10 mt-4 text-center text-xs text-white/70">Exemple fictif</p>
        </div>
    </div>

    {{-- Le motif s'efface en douceur avant la vague : plus de coupure nette --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-44 bg-gradient-to-t from-brand-600 via-brand-600/85 to-transparent" aria-hidden="true"></div>
    <x-wave fill="#ffffff" />
</section>

{{-- ====================== Ce qu'il lit : vos supports ====================== --}}
<section data-track-view="presentation" class="mx-auto max-w-6xl px-4 pb-16 pt-20 sm:px-6 lg:pb-24 lg:pt-28">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <div>
            <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Il lit ce que vous avez déjà.</h2>
            <p class="mt-4 text-lg text-slate-600">Pas besoin de tout réécrire. Envoyez vos supports tels quels : {{ $name }} les lit, les découpe et s'en souvient.</p>
            <a href="{{ route('register') }}" class="btn-primary mt-7">Ajouter mes documents</a>
        </div>

        <ul class="divide-y divide-slate-200 border-y border-slate-200">
            @foreach ([
                ['doc', 'Votre tarif en PDF ou en Word', 'PDF, Word, Excel, CSV, texte. Catalogues et listes de prix compris. Les PDF scannés sont lus aussi.'],
                ['photo', 'La photo de votre affiche, de votre menu ou de votre vitrine', 'Les prix et les horaires écrits sur l\'image sont retrouvés.'],
                ['globe', 'Votre site web, page par page', 'Lu poliment, et relu automatiquement pour rester à jour.'],
                ['link', 'Votre page Facebook', 'Collez le lien : nous vous guidons pour copier le contenu, ou pour connecter la page.'],
                ['qa', 'Vos réponses habituelles', 'Une question, une réponse exacte, mémorisée tout de suite.'],
            ] as [$icon, $title, $text])
                <li class="group flex gap-4 py-5">
                    {{-- Le rond devient une bulle au survol. --}}
                    <span class="mt-0.5 grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-50 transition-all duration-300 group-hover:rounded-2xl group-hover:rounded-bl-sm group-hover:bg-brand-100"><x-illus :name="$icon" class="h-9 w-9" /></span>
                    <div class="transition-transform duration-300 group-hover:translate-x-1">
                        <p class="font-semibold text-brand-950">{{ $title }}</p>
                        <p class="mt-0.5 text-sm text-slate-600">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>

{{-- ============== Fiabilite : la comparaison qui vend le produit ============== --}}
<section class="relative bg-mist">
    <x-wave fill="#ffffff" position="top" size="sm" :layers="1" />
    <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
        <div class="max-w-2xl">
            <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Il ne raconte pas n'importe quoi.</h2>
            <p class="mt-4 text-lg text-slate-600">Un assistant généraliste répond toujours, quitte à inventer. {{ $name }} répond d'après vos documents, cite sa source, et l'avoue quand il ne sait pas.</p>
        </div>

        <div class="mt-10 grid gap-5 md:grid-cols-2">
            <div data-tilt class="rounded-2xl rounded-bl-md border border-red-200 bg-white p-5">
                <p class="text-sm font-semibold text-red-700">Un assistant généraliste</p>
                <div class="mt-4 space-y-2.5 text-[15px]">
                    <p class="ms-auto w-fit max-w-[85%] rounded-2xl rounded-br-sm bg-slate-100 px-4 py-2">Vous fermez à quelle heure le dimanche ?</p>
                    <p class="w-fit max-w-[90%] rounded-2xl rounded-bl-sm bg-red-50 px-4 py-2 text-red-900">Nous fermons à 18 h le dimanche.</p>
                </div>
                <p class="mt-4 text-sm text-slate-600">Faux : la boutique ferme à 13 h. Le client fait le déplacement pour rien.</p>
            </div>

            <div data-tilt class="rounded-2xl rounded-bl-md border-2 border-brand-600 bg-white p-5">
                <p class="text-sm font-semibold text-brand-700">{{ $name }}</p>
                <div class="mt-4 space-y-2.5 text-[15px]">
                    <p class="ms-auto w-fit max-w-[85%] rounded-2xl rounded-br-sm bg-slate-100 px-4 py-2">Vous fermez à quelle heure le dimanche ?</p>
                    <p class="w-fit max-w-[90%] rounded-2xl rounded-bl-sm bg-brand-50 px-4 py-2">Le dimanche, nous sommes ouverts de <strong>9 h à 13 h</strong>.
                        <span class="mt-1.5 block w-fit rounded-full bg-white px-2 py-0.5 text-xs text-slate-600">Source : Horaires et adresse</span></p>
                </div>
                <p class="mt-4 text-sm text-slate-600">Et si l'information n'existe pas, il répond « je ne sais pas » et vous prévient.</p>
            </div>
        </div>

        <ul class="mt-10 grid gap-6 text-sm text-slate-700 sm:grid-cols-3">
            <li class="group flex gap-3"><x-illus name="shield" class="h-11 w-11 shrink-0" /><span><strong class="text-brand-950">Il refuse le hors-sujet</strong> : devoirs, politique, conseils médicaux.</span></li>
            <li class="group flex gap-3"><x-illus name="voice" class="h-11 w-11 shrink-0" /><span><strong class="text-brand-950">Il vous passe la main</strong> dès qu'un client le demande ou se plaint.</span></li>
            <li class="group flex gap-3"><x-illus name="chart" class="h-11 w-11 shrink-0" /><span><strong class="text-brand-950">Il vous montre ce qui lui manque</strong> : les questions sans réponse, à compléter en un clic.</span></li>
        </ul>
    </div>
    <x-wave fill="#ffffff" size="sm" :layers="1" />
</section>

{{-- ============================ Fonctionnement ============================ --}}
<section data-track-view="fonctionnement" id="fonctionnement" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24">
    <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Prêt en dix minutes.</h2>

    <div class="relative mt-12">
        {{-- La ligne se remplit pendant le defilement : elle relie les trois etapes. --}}
        <div class="steps-line absolute left-5 right-5 top-5 hidden h-1 -translate-y-1/2 rounded-full md:block" aria-hidden="true"></div>

        <ol class="relative grid gap-10 md:grid-cols-3">
            @foreach ([
                ['Décrivez votre activité', 'Choisissez votre métier et répondez à quelques questions. Nous rédigeons pour vous la consigne complète de votre assistant : ton, parcours de commande ou de rendez-vous, règles à respecter.'],
                ['Donnez-lui vos supports, puis testez', 'Ajoutez vos documents, vos photos, votre site. Posez-lui vos vraies questions dans la zone de test et voyez sur quels passages il s\'appuie.'],
                ['Publiez', 'Collez une ligne de code sur votre site. Pour WhatsApp, notre équipe active votre numéro avec vous. Vous suivez tout depuis un seul tableau de bord.'],
            ] as $i => [$title, $text])
                <li>
                    <span class="relative grid h-10 w-10 place-items-center rounded-full rounded-bl-md bg-brand-600 font-display text-base font-bold text-white ring-8 ring-white">{{ $i + 1 }}</span>
                    <h3 class="mt-5 text-lg font-bold leading-snug">{{ $title }}</h3>
                    <p class="mt-2 leading-relaxed text-slate-600">{{ $text }}</p>
                </li>
            @endforeach
        </ol>
    </div>

    <div class="mt-10">
        <a href="{{ route('register') }}" class="btn-primary px-7 py-3.5 text-base">Commencer maintenant</a>
    </div>
</section>

{{-- ================================ Metiers ================================ --}}
<section data-track-view="metiers" id="metiers" class="relative scroll-mt-20 bg-brand-950 text-white">
    <x-wave fill="#ffffff" position="top" size="sm" :layers="1" />
    <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
        <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Configuré pour votre métier dès le départ.</h2>
        <p class="mt-4 max-w-2xl text-lg text-white/75">Chaque secteur a ses parcours et ses interdits : une commande n'est pas une réservation, et une clinique ne donne pas de diagnostic.</p>

        <div class="mt-10 divide-y divide-white/15 border-y border-white/15">
            @foreach ($featured as $slug => $sector)
                <a href="{{ $sectorPages[$slug] ?? route('register') }}" class="mouth group grid items-center gap-2 py-5 md:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] md:gap-8">
                    <span class="flex items-center gap-3 font-display text-lg font-bold transition group-hover:translate-x-1 group-hover:text-accent-400">
                        <x-mark tone="light" class="h-6 w-6 shrink-0" />
                        {{ $sector['label'] }}
                    </span>
                    <span class="text-white/70 transition group-hover:text-white/90">
                        « {{ implode(' » ; « ', array_slice($sector['questions'], 0, 3)) }} »
                    </span>
                </a>
            @endforeach
        </div>
        <p class="mt-6 text-sm text-white/60">Et aussi : automobile, finance, transport, associations, et un profil « autre activité ».</p>
    </div>
    <x-wave fill="#ffffff" size="sm" :layers="1" />
</section>

{{-- ================================ WhatsApp ================================ --}}
<section data-track-view="whatsapp" id="whatsapp" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24">
    <div class="grid items-start gap-10 lg:grid-cols-2 lg:gap-16">
        <div>
            <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">WhatsApp : on s'en occupe.</h2>
            <p class="mt-4 text-lg text-slate-600">Brancher WhatsApp Business demande un compte vérifié, un numéro et une configuration technique. Vous n'avez rien de tout cela à faire : notre équipe le fait avec vous, à votre demande.</p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ route('register') }}" class="btn-primary">Demander l'activation de WhatsApp</a>
                <a href="{{ route('seo.chatbot-whatsapp') }}" class="btn-outline">Tout savoir sur le chatbot WhatsApp</a>
                @if ($wa)
                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-outline">Poser une question sur WhatsApp</a>
                @endif
            </div>
        </div>

        <ul class="space-y-4">
            @foreach ([
                ['Les messages sont inclus dans votre offre', 'Aucun compte à ouvrir chez WhatsApp, aucune carte bancaire : un volume de messages est compris chaque mois, et vous rechargez par Mobile Money si besoin.'],
                ['Les conversations arrivent dans votre boîte de réception', 'Vous voyez ce que l\'assistant répond et vous prenez la main d\'un clic quand il le faut.'],
                ['Des boutons de réponse rapide', 'Après une réponse, le client touche « Commander » ou « Voir les tarifs » au lieu d\'écrire.'],
                ['Des modèles de messages pour relancer', 'Au-delà de 24 heures, WhatsApp exige des messages approuvés : créez-les et envoyez-les depuis votre espace (offres Pro et Business).'],
            ] as [$title, $text])
                <li class="flex gap-3">
                    <span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-feuille-500/15 text-feuille-600"><x-icon name="check" class="h-3.5 w-3.5" /></span>
                    <div><p class="font-semibold text-brand-950">{{ $title }}</p><p class="text-sm text-slate-600">{{ $text }}</p></div>
                </li>
            @endforeach
        </ul>
    </div>
</section>

{{-- ================================= Tarifs ================================= --}}
<section data-track-view="tarifs" id="tarifs" class="relative scroll-mt-20 overflow-hidden bg-mist">
    {{-- Un demi-cercle qui tourne doucement pendant le defilement (navigateurs compatibles). --}}
    <svg viewBox="0 0 100 50" class="scroll-spin pointer-events-none absolute -right-24 top-10 hidden w-96 opacity-60 lg:block" aria-hidden="true"><path d="M0 50a50 50 0 0 1 100 0Z" fill="#FFE38A"/></svg>

    <x-wave fill="#ffffff" position="top" size="sm" :layers="1" />
    <div class="relative mx-auto max-w-6xl px-4 py-20 sm:px-6 lg:py-28">
        <div class="max-w-2xl">
            <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Des prix simples, dans votre monnaie.</h2>
            <p class="mt-4 text-lg text-slate-600">Commencez par un essai gratuit. Changez d'offre ou arrêtez quand vous voulez.</p>
            <x-currency-switcher class="mt-6" />
        </div>

        {{-- L'essai gratuit est une bande à part : la grille ne montre que les offres payantes. --}}
        @php
            $trial = $plans->first(fn ($p) => $p->isFree());
            $paidPlans = $plans->reject(fn ($p) => $p->isFree())->values();
            $cols = [1 => 'xl:grid-cols-1', 2 => 'xl:grid-cols-2', 3 => 'xl:grid-cols-3', 4 => 'xl:grid-cols-4'][min(4, max(1, $paidPlans->count()))];
        @endphp
        @if ($trial)
            <div data-emerge class="mt-10 flex flex-wrap items-center justify-between gap-4 rounded-2xl rounded-bl-md border border-brand-100 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-4">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-50"><x-illus name="plan" :level="1" class="h-8 w-8" /></span>
                    <div>
                        <h3 class="font-display text-lg font-bold">{{ $trial->name }}</h3>
                        <p class="text-sm text-slate-600"><strong class="text-brand-950">{{ $trial->formattedPrice() }}</strong> {{ $trial->periodLabel() }}, sans carte bancaire. {{ number_format($trial->limit('messages_per_month'), 0, ',', "\u{202F}") }} réponses, {{ $trial->limit('bots') }} assistant, {{ $trial->limit('sources') }} sources.</p>
                    </div>
                </div>
                <a href="{{ route('register') }}" class="btn-primary">{{ "Commencer l'essai gratuit" }}</a>
            </div>
        @endif
        <div data-drop-in class="relative mt-5 grid gap-5 md:grid-cols-2 {{ $cols }}">
            @foreach ($paidPlans as $plan)
                @php $hl = $plan->is_highlighted; @endphp
                <div data-tilt @class(['group flex flex-col rounded-2xl rounded-bl-md p-6 transition duration-300', 'bg-brand-600 text-white shadow-pop xl:-mt-4 xl:pb-9' => $hl, 'surface-link' => ! $hl])>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <span @class(['grid h-11 w-11 shrink-0 place-items-center rounded-full', 'bg-white' => $hl, 'bg-brand-50' => ! $hl])><x-illus name="plan" :level="['essentiel' => 1, 'bonplan' => 2, 'pro' => 3, 'business' => 4][$plan->slug] ?? 2" class="h-8 w-8" /></span>
                            <h3 class="font-display text-lg font-bold">{{ $plan->name }}</h3>
                        </div>
                        @if ($hl) <span class="rounded-full bg-accent-500 px-2.5 py-0.5 text-xs font-semibold text-brand-950">Le plus choisi</span> @endif
                    </div>
                    <p @class(['mt-1 min-h-[2.5rem] text-sm', 'text-white/80' => $hl, 'text-slate-600' => ! $hl])>{{ $plan->tagline }}</p>

                    <p class="mt-5 font-display">
                        <span class="inline-block text-[1.65rem] font-bold leading-none" data-prices="{{ json_encode($plan->formattedPrices(), JSON_UNESCAPED_UNICODE) }}">{{ $plan->formattedPrice() }}</span>
                        @if ($plan->periodLabel() !== '') <span @class(['mt-1.5 block font-sans text-sm font-normal', 'text-white/80' => $hl, 'text-slate-500' => ! $hl])>{{ $plan->periodLabel() }}</span> @endif
                    </p>

                    <ul @class(['mt-5 flex-1 space-y-2.5 text-sm', 'text-white/90' => $hl, 'text-slate-700' => ! $hl])>
                        <li>{{ $plan->limit('bots') }} assistant{{ $plan->limit('bots') > 1 ? 's' : '' }}</li>
                        <li>{{ number_format($plan->limit('messages_per_month'), 0, ',', "\u{202F}") }} réponses par mois</li>
                        <li>{{ $plan->limit('sources') }} sources de connaissances</li>
                        <li>Sites lus jusqu'à {{ $plan->limit('pages_per_crawl') }} pages</li>
                        <li>{{ $plan->limit('members') }} utilisateur{{ $plan->limit('members') > 1 ? 's' : '' }}</li>
                        @if ($plan->limit('whatsapp_messages_per_month') > 0)
                            <li>WhatsApp : {{ number_format($plan->limit('whatsapp_messages_per_month'), 0, ',', "\u{202F}") }} messages par mois inclus</li>
                        @else
                            <li class="opacity-50 line-through">WhatsApp géré par notre équipe</li>
                        @endif
                        <li @class(['opacity-50 line-through' => ! $plan->feature('voice')])>Messages vocaux{{ $plan->feature('voice') ? ' ('.number_format($plan->limit('voice_per_month'), 0, ',', "\u{202F}").' par mois)' : '' }}</li>
                        <li @class(['opacity-50 line-through' => ! $plan->feature('templates')])>Modèles de messages WhatsApp</li>
                        <li @class(['opacity-50 line-through' => ! $plan->feature('chat_import')])>Import de vos discussions WhatsApp</li>
                        <li @class(['opacity-50 line-through' => ! $plan->feature('remove_branding')])>Sans mention « Propulsé par {{ $name }} »</li>
                        @if ($plan->feature('priority_support')) <li>Assistance prioritaire</li> @endif
                    </ul>

                    <a href="{{ route('register') }}" @class(['mt-6', 'btn-accent' => $hl, 'btn-outline' => ! $hl])>
                        {{ $plan->isFree() ? 'Commencer l\'essai gratuit' : 'Choisir '.$plan->name }}
                    </a>
                </div>
            @endforeach
        </div>

        {{-- Options a la carte --}}
        @php $pack = config('platform.billing.wa_pack'); $addon = config('platform.billing.addons.chat_import'); @endphp
        <div class="mt-8 grid gap-4 md:grid-cols-2">
            <div data-tilt class="surface-link group flex items-start gap-4 p-5">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-brand-50"><x-illus name="chat" class="h-8 w-8" /></span>
                <div>
                    <p class="font-semibold text-brand-950">Besoin de plus de messages WhatsApp ?</p>
                    <p class="mt-1 text-sm text-slate-600">Rechargez par lots de {{ number_format($pack['messages'], 0, ',', "\u{202F}") }} messages, avec Mobile Money : <strong class="text-brand-950" data-prices="{{ json_encode(\App\Support\Currency::formatPrices($pack['prices']), JSON_UNESCAPED_UNICODE) }}">{{ \App\Support\Currency::formatPrices($pack['prices'])[\App\Support\Currency::current()] }}</strong>.</p>
                </div>
            </div>
            <div data-tilt class="surface-link group flex items-start gap-4 p-5">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-brand-50"><x-illus name="qa" class="h-8 w-8" /></span>
                <div>
                    <p class="font-semibold text-brand-950">{{ $addon['name'] }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $addon['description'] }} Inclus dès Pro ; pour l'offre Essentiel : <strong class="text-brand-950" data-prices="{{ json_encode(\App\Support\Currency::formatPrices($addon['prices']), JSON_UNESCAPED_UNICODE) }}">{{ \App\Support\Currency::formatPrices($addon['prices'])[\App\Support\Currency::current()] }}</strong> une fois.</p>
                </div>
            </div>
        </div>

        <p class="mt-6 text-sm text-slate-600">Prix par mois. Paiement par Orange Money, Moov Money, Coris Money ou virement : vous envoyez la référence, l'offre est activée. Les messages WhatsApp sont inclus dans votre offre : aucun compte à ouvrir chez WhatsApp, aucune carte bancaire. Vous développez une application ? <a href="{{ route('developers') }}" class="font-semibold text-brand-700 underline decoration-brand-300 underline-offset-2 hover:text-brand-900">Découvrez l'API pour développeurs</a>.</p>
    </div>
</section>

{{-- ============================ On gere tout pour vous ============================ --}}
<section class="bg-white">
    <div class="mx-auto max-w-6xl px-4 pb-4 pt-2 sm:px-6 lg:pb-8">
        <div data-ripple-field class="relative overflow-hidden rounded-[2rem] rounded-bl-lg bg-brand-950 px-6 py-10 text-white sm:px-10 lg:px-14 lg:py-14">
            <div class="wax-navy absolute inset-0 opacity-70" aria-hidden="true"></div>
            <svg viewBox="0 0 100 50" class="drift pointer-events-none absolute -right-10 -top-6 w-56 opacity-90" style="--dx: -10px; --dy: 12px; --t: 10s; --r0: 4deg; --r1: -3deg" aria-hidden="true"><path d="M0 50a50 50 0 0 1 100 0Z" fill="#FFB400"/></svg>

            <div class="relative grid items-center gap-8 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] lg:gap-14">
                <div>
                    <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Vous voulez qu'on gère tout pour vous ?</h2>
                    <p class="mt-4 max-w-xl text-lg text-white/80">Nous créons votre assistant, lisons vos documents, activons WhatsApp et gardons vos tarifs à jour. Vous ne faites qu'une chose : répondre aux clients qui demandent une personne.</p>
                    <ul class="mt-6 grid gap-2.5 text-sm text-white/85 sm:grid-cols-2">
                        @foreach (['Création et réglage de votre assistant', 'Activation de WhatsApp Business', 'Mise à jour de vos tarifs et documents', 'Suivi des questions restées sans réponse'] as $point)
                            <li class="flex items-start gap-2.5"><span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-feuille-500 text-white"><x-icon name="check" class="h-3 w-3" /></span>{{ $point }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="flex flex-col gap-3">
                    @if ($wa)
                        <a data-magnetic href="{{ 'https://wa.me/'.$brand['whatsapp'].'?text='.rawurlencode("Bonjour, je voudrais que vous gériez mon assistant {$name} pour moi.") }}" target="_blank" rel="noopener" class="btn-accent justify-center py-4 text-base">
                            <x-icon name="chat" class="h-5 w-5" /> Écrivez-nous sur WhatsApp
                        </a>
                    @endif
                    @if ($brand['email'])
                        <a href="mailto:{{ $brand['email'] }}?subject={{ rawurlencode('Je voudrais que vous gériez mon assistant') }}" class="btn justify-center border border-white/40 py-4 text-base text-white hover:bg-white/10">Écrire par e-mail</a>
                    @endif
                    @unless ($wa || $brand['email'])
                        <a data-magnetic href="{{ route('register') }}" class="btn-accent justify-center py-4 text-base">Demander à être accompagné</a>
                    @endunless
                    <p class="text-center text-xs text-white/60">Un devis clair, sans engagement.</p>
                </div>
            </div>
        </div>
    </div>
</section>
{{-- ================================== FAQ ================================== --}}
<section data-track-view="questions" id="questions" class="mx-auto max-w-3xl scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24">
    <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Questions fréquentes</h2>
    <div class="mt-8 divide-y divide-slate-200 border-y border-slate-200">
        @foreach ($faq as [$q, $a])
            <details class="group py-5">
                <summary class="flex cursor-pointer items-center justify-between gap-4 font-semibold text-brand-950 transition hover:text-brand-700">
                    {{ $q }}
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600 transition duration-300 group-open:rotate-45 group-open:bg-brand-600 group-open:text-white" aria-hidden="true"><x-icon name="x" class="h-4 w-4 rotate-45" /></span>
                </summary>
                <p class="pt-3 leading-relaxed text-slate-600">{{ $a }}</p>
            </details>
        @endforeach
    </div>
</section>

{{-- ============================ Appel final ============================ --}}
<section data-track-view="appel-final" class="wax relative overflow-hidden text-white" data-parallax data-ripple-field>
    <x-wave fill="#ffffff" position="top" size="sm" :layers="1" />
    <div class="pointer-events-none absolute inset-0 hidden md:block" aria-hidden="true">
        <svg viewBox="0 0 100 50" class="drift parallax absolute -left-10 top-6 w-52" style="--dx: 12px; --dy: -12px; --t: 10s; --depth: 18px; --r0: 6deg; --r1: -4deg"><path d="M0 50a50 50 0 0 1 100 0Z" fill="#FFB400"/></svg>
        <svg viewBox="0 0 100 50" class="drift parallax absolute -right-8 bottom-4 w-56" style="--dx: -12px; --dy: 12px; --t: 12s; --depth: -18px; --r0: -6deg; --r1: 4deg"><path d="M0 0a50 50 0 0 0 100 0Z" fill="#E8336D"/></svg>
    </div>
    <div class="relative mx-auto max-w-4xl px-4 pb-28 pt-24 text-center sm:px-6 lg:pb-36 lg:pt-32">
        <h2 data-emerge class="font-display text-2xl font-bold leading-tight sm:text-3xl">Essayez avec vos propres documents.</h2>
        <p class="mx-auto mt-4 max-w-xl text-lg text-white/90">{{ $trial ? "L'essai gratuit de {$trial->trial_days} jours suffit pour construire et tester votre premier assistant." : 'Le compte gratuit suffit pour construire et tester votre premier assistant.' }} Vous décidez ensuite.</p>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('register') }}" class="btn-accent px-7 py-3.5 text-base">Créer mon assistant gratuitement</a>
            @if ($landingBotKey)
                <button type="button" onclick="window.KoumaWidget && window.KoumaWidget.open()" class="btn border border-white/40 px-7 py-3.5 text-base text-white hover:bg-white/10">Discuter avec {{ $name }}</button>
            @endif
            @if ($wa)
                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn border border-white/40 px-7 py-3.5 text-base text-white hover:bg-white/10">Écrire sur WhatsApp</a>
            @endif
        </div>
    </div>
    <x-wave fill="#0B1340" />
</section>
</main>

<x-site-footer :name="$name" :aliases="$aliases" :wa="$wa" />

@if ($landingBotKey)
    <script src="{{ url('/widget/widget.js') }}" data-bot="{{ $landingBotKey }}" async></script>
@endif
</body>
</html>
