@php
    $name = $brand['name'];
    $wa = $brand['whatsapp'] ? 'https://wa.me/'.$brand['whatsapp'].'?text='.rawurlencode("Bonjour, je voudrais en savoir plus sur {$name}.") : null;
    $description = "Créez le chatbot de votre entreprise à partir de vos documents, photos et liens. Il répond à vos clients sur votre site web et sur WhatsApp, à toute heure, et vous passe la main quand il le faut.";
    $featured = collect($sectors)->only(['commerce', 'restaurant', 'sante', 'education', 'immobilier', 'beaute', 'hotellerie', 'services']);
    $faq = [
        ['Mon assistant peut-il inventer un prix ou un horaire ?', "Il est construit pour ne pas le faire : il ne répond qu'à partir de vos documents et de vos textes. Quand l'information manque, il le dit et propose de contacter votre équipe. Vous voyez ensuite la liste des questions restées sans réponse et vous y répondez en une minute."],
        ['Faut-il avoir un site web ?', "Non. Vous pouvez donner vos documents, vos photos d'affiches ou de menus, ou coller du texte. Votre assistant vit sur votre site si vous en avez un, sur WhatsApp, ou sur une page de démonstration que vous partagez par lien."],
        ['Comment se passe WhatsApp ?', "Vous nous indiquez le numéro à utiliser. Notre équipe technique s'occupe de la configuration avec vous : compte WhatsApp Business, vérification, branchement. Vous gardez la propriété de votre numéro et vous suivez les conversations depuis votre tableau de bord."],
        ['Et si un client veut parler à une personne ?', "Il le dit, et l'assistant vous passe la main : vous êtes prévenu par e-mail, la conversation apparaît dans votre boîte de réception et vous répondez au client sur le même canal, sur le site ou sur WhatsApp."],
        ['Mes documents servent-ils à d\'autres entreprises ?', "Non. Chaque entreprise a son espace isolé. Vos documents ne servent qu'à votre assistant, et vous pouvez les supprimer à tout moment."],
        ['Quelles langues comprend-il ?', "Il répond en français, en anglais et en arabe, dans la langue de votre client. Les langues locales (mooré, dioula, shikomori) ne sont pas garanties : testez avec vos propres phrases avant de vous engager."],
        ['Comment payer ?', "Par Orange Money, Moov Money, Coris Money ou virement. Vous nous envoyez la référence du paiement et votre offre est activée. Vous pouvez changer d'offre ou arrêter à tout moment."],
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }} : l'assistant qui répond à vos clients, sur votre site et sur WhatsApp</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ url('/') }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $name }} : un assistant qui connaît votre entreprise">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:locale" content="fr_FR">
    <meta name="twitter:card" content="summary">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=bricolage-grotesque:600,700|instrument-sans:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => $name,
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'description' => $description,
        'offers' => $plans->map(fn ($p) => ['@type' => 'Offer', 'name' => $p->name, 'price' => (string) $p->price, 'priceCurrency' => $p->currency])->values(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
</head>
<body class="bg-white">

{{-- ============================== En-tete ============================== --}}
<header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/90 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
        <a href="{{ route('home') }}" aria-label="{{ $name }}, accueil"><x-logo /></a>

        <nav class="hidden items-center gap-7 text-sm font-medium text-slate-700 md:flex" aria-label="Sections de la page">
            <a href="#fonctionnement" class="hover:text-brand-600">Comment ça marche</a>
            <a href="#metiers" class="hover:text-brand-600">Métiers</a>
            <a href="#whatsapp" class="hover:text-brand-600">WhatsApp</a>
            <a href="#tarifs" class="hover:text-brand-600">Tarifs</a>
            <a href="#questions" class="hover:text-brand-600">Questions</a>
        </nav>

        <div class="flex items-center gap-2">
            @auth
                <a href="{{ route('dashboard') }}" class="btn-primary">Mon espace</a>
            @else
                <a href="{{ route('login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:text-brand-600 sm:inline-block">Se connecter</a>
                <a href="{{ route('register') }}" class="btn-accent">Créer mon assistant</a>
            @endauth
        </div>
    </div>
</header>

<main>
{{-- ================================ Heros ================================ --}}
<section class="wax relative overflow-hidden text-white">
    <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-14 sm:px-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:py-20">
        <div>
            <h1 class="font-display text-4xl font-bold leading-[1.05] sm:text-5xl lg:text-[3.5rem]">
                Répondez à vos clients à toute heure, sans y passer vos journées.
            </h1>
            <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/85">
                {{ $name }} lit vos tarifs, vos affiches et votre site, puis répond à votre place sur votre site web et sur WhatsApp. Quand une question dépasse le robot, il vous passe la main.
            </p>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <a href="{{ route('register') }}" class="btn-accent px-6 py-3 text-base">Créer mon assistant gratuitement</a>
                @if ($landingBotKey)
                    <button type="button" onclick="window.KoumaWidget && window.KoumaWidget.open()" class="btn border border-white/40 px-6 py-3 text-base text-white hover:bg-white/10">Essayer une démonstration</button>
                @else
                    <a href="#fonctionnement" class="btn border border-white/40 px-6 py-3 text-base text-white hover:bg-white/10">Voir comment ça marche</a>
                @endif
            </div>

            <p class="mt-5 max-w-lg text-sm text-white/75">
                Gratuit pour démarrer, sans carte bancaire. Ensuite, paiement par Orange Money, Moov Money ou virement.
                @if ($wa)
                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="ms-1 font-medium text-accent-300 underline underline-offset-4 hover:text-accent-200">Parler à un conseiller sur WhatsApp</a>
                @endif
            </p>
        </div>

        {{-- Une conversation qui se joue une fois : la reponse chiffree, la source, le passage a un humain --}}
        <div class="mx-auto w-full max-w-sm" role="img" aria-label="Exemple de conversation : un client demande la livraison et le prix d'un boubou, l'assistant répond avec les tarifs de la boutique, puis prévient la gérante quand le client veut parler à quelqu'un.">
            <div class="rounded-[2rem] bg-brand-950 p-2.5 shadow-lift ring-1 ring-white/15">
                <div class="overflow-hidden rounded-[1.5rem] bg-slate-100 text-brand-950">
                    <div class="flex items-center gap-3 bg-brand-700 px-4 py-3 text-white">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-accent-500 text-sm font-bold text-brand-950">A</span>
                        <div class="leading-tight">
                            <p class="text-sm font-semibold">Boutique Awa</p>
                            <p class="text-xs text-white/75">assistant en ligne</p>
                        </div>
                    </div>
                    <div class="flex h-[25rem] flex-col justify-end gap-2.5 px-3 py-4 text-[13.5px] leading-snug">
                        <div class="say say-1 ms-auto max-w-[80%] rounded-2xl rounded-br-sm bg-brand-100 px-3.5 py-2">Bonsoir, vous livrez à Bobo ?</div>
                        <div class="say say-2 max-w-[86%] rounded-2xl rounded-bl-sm bg-white px-3.5 py-2 shadow-sm">
                            Oui, à Bobo-Dioulasso : <strong>2 000 FCFA</strong>, livré sous 48 h. Gratuit dès 25 000 FCFA d'achat.
                        </div>
                        <div class="say say-3 ms-auto max-w-[80%] rounded-2xl rounded-br-sm bg-brand-100 px-3.5 py-2">Le boubou brodé, c'est combien ?</div>
                        <div class="say say-4 max-w-[86%] rounded-2xl rounded-bl-sm bg-white px-3.5 py-2 shadow-sm">
                            Le boubou brodé pour homme coûte <strong>35 000 FCFA</strong>. Je vous prépare la commande ?
                            <span class="mt-1.5 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">Source : Catalogue et prix</span>
                        </div>
                        <div class="say say-5 ms-auto max-w-[80%] rounded-2xl rounded-br-sm bg-brand-100 px-3.5 py-2">Je veux parler à Awa</div>
                        <div class="say say-6 max-w-[86%] rounded-2xl rounded-bl-sm bg-white px-3.5 py-2 shadow-sm">Bien sûr, je préviens Awa : elle vous répond ici.</div>
                    </div>
                </div>
            </div>
            <p class="mt-3 text-center text-xs text-white/60">Exemple fictif</p>
        </div>
    </div>
</section>

{{-- ====================== Ce qu'il lit : vos supports ====================== --}}
<section class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
    <div class="grid gap-10 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-16">
        <div>
            <h2 class="font-display text-3xl font-bold leading-tight sm:text-4xl">Il lit ce que vous avez déjà.</h2>
            <p class="mt-4 text-lg text-slate-600">Pas besoin de tout réécrire. Envoyez vos supports tels quels : {{ $name }} les lit, les découpe et s'en souvient.</p>
            <a href="{{ route('register') }}" class="btn-primary mt-7">Ajouter mes documents</a>
        </div>

        <ul class="divide-y divide-slate-200 border-y border-slate-200">
            @foreach ([
                ['book', 'Votre tarif en PDF ou en Word', 'PDF, Word, texte, tableurs CSV. Les PDF scannés sont lus aussi.'],
                ['chat', 'La photo de votre affiche, de votre menu ou de votre vitrine', 'Les prix et les horaires écrits sur l\'image sont retrouvés.'],
                ['globe', 'Votre site web, page par page', 'Lu poliment, et relu automatiquement pour rester à jour.'],
                ['link', 'Votre page Facebook', 'Collez le lien : nous vous guidons pour copier le contenu, ou pour connecter la page.'],
                ['wand', 'Vos réponses habituelles', 'Une question, une réponse exacte, mémorisée tout de suite.'],
            ] as [$icon, $title, $text])
                <li class="flex gap-4 py-5">
                    <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-icon :name="$icon" /></span>
                    <div>
                        <p class="font-semibold text-brand-950">{{ $title }}</p>
                        <p class="mt-0.5 text-sm text-slate-600">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>

{{-- ============== Fiabilite : la comparaison qui vend le produit ============== --}}
<section class="bg-mist">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
        <div class="max-w-2xl">
            <h2 class="font-display text-3xl font-bold leading-tight sm:text-4xl">Il ne raconte pas n'importe quoi.</h2>
            <p class="mt-4 text-lg text-slate-600">Un assistant généraliste répond toujours, quitte à inventer. {{ $name }} répond d'après vos documents, cite sa source, et l'avoue quand il ne sait pas.</p>
        </div>

        <div class="mt-10 grid gap-5 md:grid-cols-2">
            <div class="rounded-2xl border border-red-200 bg-white p-5">
                <p class="text-sm font-semibold text-red-700">Un assistant généraliste</p>
                <div class="mt-4 space-y-2.5 text-[15px]">
                    <p class="ms-auto w-fit max-w-[85%] rounded-2xl rounded-br-sm bg-slate-100 px-4 py-2">Vous fermez à quelle heure le dimanche ?</p>
                    <p class="w-fit max-w-[90%] rounded-2xl rounded-bl-sm bg-red-50 px-4 py-2 text-red-900">Nous fermons à 18 h le dimanche.</p>
                </div>
                <p class="mt-4 text-sm text-slate-600">Faux : la boutique ferme à 13 h. Le client fait le déplacement pour rien.</p>
            </div>

            <div class="rounded-2xl border-2 border-brand-600 bg-white p-5">
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
            <li class="flex gap-3"><x-icon name="shield" class="h-5 w-5 shrink-0 text-brand-600" /><span><strong class="text-brand-950">Il refuse le hors-sujet</strong> : devoirs, politique, conseils médicaux.</span></li>
            <li class="flex gap-3"><x-icon name="users" class="h-5 w-5 shrink-0 text-brand-600" /><span><strong class="text-brand-950">Il vous passe la main</strong> dès qu'un client le demande ou se plaint.</span></li>
            <li class="flex gap-3"><x-icon name="chart" class="h-5 w-5 shrink-0 text-brand-600" /><span><strong class="text-brand-950">Il vous montre ce qui lui manque</strong> : les questions sans réponse, à compléter en un clic.</span></li>
        </ul>
    </div>
</section>

{{-- ============================ Fonctionnement ============================ --}}
<section id="fonctionnement" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24">
    <h2 class="font-display text-3xl font-bold leading-tight sm:text-4xl">Prêt en dix minutes.</h2>
    <ol class="mt-10 grid gap-8 md:grid-cols-3">
        @foreach ([
            ['Décrivez votre activité', 'Choisissez votre métier et répondez à quelques questions. Nous rédigeons pour vous la consigne complète de votre assistant : ton, parcours de commande ou de rendez-vous, règles à respecter.'],
            ['Donnez-lui vos supports, puis testez', 'Ajoutez vos documents, vos photos, votre site. Posez-lui vos vraies questions dans la zone de test et voyez sur quels passages il s\'appuie.'],
            ['Publiez', 'Collez une ligne de code sur votre site. Pour WhatsApp, notre équipe active votre numéro avec vous. Vous suivez tout depuis un seul tableau de bord.'],
        ] as $i => [$title, $text])
            <li class="border-t-2 border-brand-600 pt-5">
                <p class="font-display text-sm font-bold text-brand-600">Étape {{ $i + 1 }}</p>
                <h3 class="mt-2 text-xl font-bold">{{ $title }}</h3>
                <p class="mt-2 leading-relaxed text-slate-600">{{ $text }}</p>
            </li>
        @endforeach
    </ol>
    <div class="mt-10">
        <a href="{{ route('register') }}" class="btn-primary px-6 py-3 text-base">Commencer maintenant</a>
    </div>
</section>

{{-- ================================ Metiers ================================ --}}
<section id="metiers" class="scroll-mt-20 bg-brand-950 text-white">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
        <h2 class="font-display text-3xl font-bold leading-tight sm:text-4xl">Configuré pour votre métier dès le départ.</h2>
        <p class="mt-4 max-w-2xl text-lg text-white/75">Chaque secteur a ses parcours et ses interdits : une commande n'est pas une réservation, et une clinique ne donne pas de diagnostic.</p>

        <div class="mt-10 divide-y divide-white/15 border-y border-white/15">
            @foreach ($featured as $slug => $sector)
                <a href="{{ route('register') }}" class="group grid gap-2 py-5 md:grid-cols-[minmax(0,4fr)_minmax(0,7fr)] md:gap-8">
                    <span class="font-display text-xl font-bold group-hover:text-accent-400">{{ $sector['label'] }}</span>
                    <span class="text-white/70">
                        « {{ implode(' » ; « ', array_slice($sector['questions'], 0, 3)) }} »
                    </span>
                </a>
            @endforeach
        </div>
        <p class="mt-6 text-sm text-white/60">Et aussi : automobile, finance, transport, associations, et un profil « autre activité ».</p>
    </div>
</section>

{{-- ================================ WhatsApp ================================ --}}
<section id="whatsapp" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24">
    <div class="grid items-start gap-10 lg:grid-cols-2 lg:gap-16">
        <div>
            <h2 class="font-display text-3xl font-bold leading-tight sm:text-4xl">WhatsApp : on s'en occupe.</h2>
            <p class="mt-4 text-lg text-slate-600">Brancher WhatsApp Business demande un compte vérifié, un numéro et une configuration technique. Vous n'avez rien de tout cela à faire : notre équipe le fait avec vous, à votre demande.</p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ route('register') }}" class="btn-primary">Demander l'activation de WhatsApp</a>
                @if ($wa)
                    <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-outline">Poser une question sur WhatsApp</a>
                @endif
            </div>
        </div>

        <ul class="space-y-4">
            @foreach ([
                ['Vous gardez votre numéro et votre compte', 'Le numéro reste le vôtre. Vous payez vos messages à WhatsApp, sans marge cachée de notre part.'],
                ['Les conversations arrivent dans votre boîte de réception', 'Vous voyez ce que l\'assistant répond et vous prenez la main d\'un clic quand il le faut.'],
                ['Des boutons de réponse rapide', 'Après une réponse, le client touche « Commander » ou « Voir les tarifs » au lieu d\'écrire.'],
                ['Des modèles de messages pour relancer', 'Au-delà de 24 heures, WhatsApp exige des messages approuvés : créez-les et envoyez-les depuis votre espace (offres Pro et Business).'],
            ] as [$title, $text])
                <li class="flex gap-3">
                    <span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700"><x-icon name="check" class="h-3.5 w-3.5" /></span>
                    <div><p class="font-semibold text-brand-950">{{ $title }}</p><p class="text-sm text-slate-600">{{ $text }}</p></div>
                </li>
            @endforeach
        </ul>
    </div>
</section>

{{-- ================================= Tarifs ================================= --}}
<section id="tarifs" class="scroll-mt-20 bg-mist">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:py-24">
        <div class="max-w-2xl">
            <h2 class="font-display text-3xl font-bold leading-tight sm:text-4xl">Des prix simples, en FCFA.</h2>
            <p class="mt-4 text-lg text-slate-600">Commencez gratuitement. Changez d'offre ou arrêtez quand vous voulez.</p>
        </div>

        @php $cols = [2 => 'xl:grid-cols-2', 3 => 'xl:grid-cols-3', 4 => 'xl:grid-cols-4'][min(4, max(2, $plans->count()))]; @endphp
        <div class="mt-10 grid gap-5 md:grid-cols-2 {{ $cols }}">
            @foreach ($plans as $plan)
                @php $hl = $plan->is_highlighted; @endphp
                <div @class(['flex flex-col rounded-2xl p-6', 'bg-brand-600 text-white shadow-lift xl:-mt-3 xl:pb-9' => $hl, 'border border-slate-200 bg-white' => ! $hl])>
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-display text-xl font-bold">{{ $plan->name }}</h3>
                        @if ($hl) <span class="rounded-full bg-accent-500 px-2.5 py-0.5 text-xs font-semibold text-brand-950">Le plus choisi</span> @endif
                    </div>
                    <p @class(['mt-1 min-h-[2.5rem] text-sm', 'text-white/80' => $hl, 'text-slate-600' => ! $hl])>{{ $plan->tagline }}</p>

                    <p class="mt-4 font-display">
                        <span class="text-3xl font-bold">{{ $plan->formattedPrice() }}</span>
                        @unless ($plan->isFree()) <span @class(['text-sm font-normal', 'text-white/80' => $hl, 'text-slate-500' => ! $hl])>par mois</span> @endunless
                    </p>

                    <ul @class(['mt-5 flex-1 space-y-2.5 text-sm', 'text-white/90' => $hl, 'text-slate-700' => ! $hl])>
                        <li>{{ $plan->limit('bots') }} assistant{{ $plan->limit('bots') > 1 ? 's' : '' }}</li>
                        <li>{{ number_format($plan->limit('messages_per_month'), 0, ',', "\u{202F}") }} réponses par mois</li>
                        <li>{{ $plan->limit('sources') }} sources de connaissances</li>
                        <li>Sites lus jusqu'à {{ $plan->limit('pages_per_crawl') }} pages</li>
                        <li>{{ $plan->limit('members') }} utilisateur{{ $plan->limit('members') > 1 ? 's' : '' }}</li>
                        <li @class(['opacity-50 line-through' => ! $plan->feature('whatsapp')])>WhatsApp géré par notre équipe</li>
                        <li @class(['opacity-50 line-through' => ! $plan->feature('templates')])>Modèles de messages WhatsApp</li>
                        <li @class(['opacity-50 line-through' => ! $plan->feature('remove_branding')])>Sans mention « Propulsé par {{ $name }} »</li>
                        @if ($plan->feature('priority_support')) <li>Assistance prioritaire</li> @endif
                    </ul>

                    <a href="{{ route('register') }}" @class(['mt-6', 'btn-accent' => $hl, 'btn-outline' => ! $hl])>
                        {{ $plan->isFree() ? 'Commencer gratuitement' : 'Choisir '.$plan->name }}
                    </a>
                </div>
            @endforeach
        </div>

        <p class="mt-6 text-sm text-slate-600">Prix par mois. Paiement par Orange Money, Moov Money, Coris Money ou virement : vous envoyez la référence, l'offre est activée. Les messages WhatsApp sont facturés par WhatsApp, sur votre compte.</p>
    </div>
</section>

{{-- ================================== FAQ ================================== --}}
<section id="questions" class="mx-auto max-w-3xl scroll-mt-20 px-4 py-16 sm:px-6 lg:py-24">
    <h2 class="font-display text-3xl font-bold leading-tight sm:text-4xl">Questions fréquentes</h2>
    <div class="mt-8 divide-y divide-slate-200 border-y border-slate-200">
        @foreach ($faq as [$q, $a])
            <details class="group py-5">
                <summary class="flex cursor-pointer items-center justify-between gap-4 font-semibold text-brand-950">
                    {{ $q }}
                    <span class="text-brand-600 transition group-open:rotate-45" aria-hidden="true"><x-icon name="x" class="h-5 w-5 rotate-45" /></span>
                </summary>
                <p class="mt-3 leading-relaxed text-slate-600">{{ $a }}</p>
            </details>
        @endforeach
    </div>
</section>

{{-- ============================ Appel final ============================ --}}
<section class="wax text-white">
    <div class="mx-auto max-w-4xl px-4 py-16 text-center sm:px-6 lg:py-20">
        <h2 class="font-display text-3xl font-bold leading-tight sm:text-4xl">Essayez avec vos propres documents.</h2>
        <p class="mx-auto mt-4 max-w-xl text-lg text-white/85">Le compte gratuit suffit pour construire et tester votre premier assistant. Vous décidez ensuite.</p>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('register') }}" class="btn-accent px-6 py-3 text-base">Créer mon assistant gratuitement</a>
            @if ($landingBotKey)
                <button type="button" onclick="window.KoumaWidget && window.KoumaWidget.open()" class="btn border border-white/40 px-6 py-3 text-base text-white hover:bg-white/10">Discuter avec {{ $name }}</button>
            @endif
            @if ($wa)
                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn border border-white/40 px-6 py-3 text-base text-white hover:bg-white/10">Écrire sur WhatsApp</a>
            @endif
        </div>
    </div>
</section>
</main>

{{-- ================================ Pied de page ================================ --}}
<footer class="bg-white">
    <div class="mx-auto flex max-w-6xl flex-col gap-8 px-4 py-10 sm:px-6 md:flex-row md:justify-between">
        <div class="max-w-xs">
            <x-logo />
            <p class="mt-3 text-sm text-slate-600">{{ $brand['tagline'] }}</p>
            <p class="mt-2 text-xs text-slate-500">{{ $name }} vient de « kuma », la parole en bambara et en dioula.</p>
        </div>
        <nav class="grid grid-cols-2 gap-x-12 gap-y-2 text-sm text-slate-700" aria-label="Liens du pied de page">
            <a href="{{ route('register') }}" class="hover:text-brand-600">Créer un compte</a>
            <a href="{{ route('login') }}" class="hover:text-brand-600">Se connecter</a>
            <a href="#tarifs" class="hover:text-brand-600">Tarifs</a>
            <a href="#questions" class="hover:text-brand-600">Questions</a>
            <a href="{{ route('legal.terms') }}" class="hover:text-brand-600">Conditions d'utilisation</a>
            <a href="{{ route('legal.privacy') }}" class="hover:text-brand-600">Confidentialité</a>
            @if ($brand['email']) <a href="mailto:{{ $brand['email'] }}" class="hover:text-brand-600">{{ $brand['email'] }}</a> @endif
            @if ($wa) <a href="{{ $wa }}" target="_blank" rel="noopener" class="hover:text-brand-600">WhatsApp</a> @endif
        </nav>
    </div>
    <p class="border-t border-slate-200 py-4 text-center text-xs text-slate-500">© {{ now()->year }} {{ $name }}</p>
</footer>

@if ($landingBotKey)
    <script src="{{ url('/widget/widget.js') }}" data-bot="{{ $landingBotKey }}" async></script>
@endif
</body>
</html>
